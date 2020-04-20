<?php

namespace App\Models;

use DB;
use Fico7489\Laravel\Pivot\Traits\PivotEventTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LibreNMS\Exceptions\InvalidIpException;
use LibreNMS\Util\IP;
use LibreNMS\Util\IPv4;
use LibreNMS\Util\IPv6;
use LibreNMS\Util\Url;
use LibreNMS\Util\Time;
use Permissions;

class Device extends BaseModel
{
    use PivotEventTrait;

    public $timestamps = false;
    protected $primaryKey = 'device_id';
    protected $fillable = ['hostname', 'ip', 'status', 'status_reason'];
    protected $casts = [
        'last_polled' => 'datetime',
        'status' => 'boolean',
    ];

    /**
     * Initialize this class
     */
    public static function boot()
    {
        parent::boot();
        self::loadAllOs(true);

        static::deleting(function (Device $device) {
            // delete related data
            $device->ports()->delete();
            $device->syslogs()->delete();
            $device->eventlogs()->delete();
            $device->applications()->delete();

            // handle device dependency updates
            $device->children->each->updateMaxDepth($device->device_id);
        });

        // handle device dependency updates
        static::updated(function (Device $device) {
            if ($device->isDirty('max_depth')) {
                $device->children->each->updateMaxDepth();
            }
        });

        static::pivotAttached(function (Device $device, $relationName, $pivotIds, $pivotIdsAttributes) {
            if ($relationName == 'parents') {
                // a parent attached to this device

                // update the parent's max depth incase it used to be standalone
                Device::whereIn('device_id', $pivotIds)->get()->each->validateStandalone();

                // make sure this device's max depth is updated
                $device->updateMaxDepth();
            } elseif ($relationName == 'children') {
                // a child device attached to this device

                // if this device used to be standalone, we need to udpate max depth
                $device->validateStandalone();

                // make sure the child's max depth is updated
                Device::whereIn('device_id', $pivotIds)->get()->each->updateMaxDepth();
            }
        });

        static::pivotDetached(function (Device $device, $relationName, $pivotIds) {
            if ($relationName == 'parents') {
                // this device detached from a parent

                // update this devices max depth
                $device->updateMaxDepth();

                // parent may now be standalone, update old parent
                Device::whereIn('device_id', $pivotIds)->get()->each->validateStandalone();
            } elseif ($relationName == 'children') {
                // a child device detached from this device

                // update the detached child's max_depth
                Device::whereIn('device_id', $pivotIds)->get()->each->updateMaxDepth();

                // this device may be standalone, update it
                $device->validateStandalone();
            }
        });
    }

    // ---- Helper Functions ----

    public static function findByHostname($hostname)
    {
        return static::where('hostname', $hostname)->first();
    }

    /**
     * Returns IP/Hostname where polling will be targeted to
     *
     * @param string $device hostname which will be triggered
     *        array  $device associative array with device data
     * @return string IP/Hostname to which Device polling is targeted
     */
    public static function pollerTarget($device)
    {
        if (! is_array($device)) {
            $ret = static::where('hostname', $device)->first(['hostname', 'overwrite_ip']);
            if (empty($ret)) {
                return $device;
            }
            $overwrite_ip = $ret->overwrite_ip;
            $hostname = $ret->hostname;
        } elseif (array_key_exists('overwrite_ip', $device)) {
            $overwrite_ip = $device['overwrite_ip'];
            $hostname = $device['hostname'];
        } else {
            return $device['hostname'];
        }
        return $overwrite_ip ?: $hostname;
    }

    public static function findByIp($ip)
    {
        if (!IP::isValid($ip)) {
            return null;
        }

        $device = static::where('hostname', $ip)->orWhere('ip', inet_pton($ip))->first();

        if ($device) {
            return $device;
        }

        try {
            $ipv4 = new IPv4($ip);
            $port = Ipv4Address::where('ipv4_address', (string) $ipv4)
                ->with('port', 'port.device')
                ->firstOrFail()->port;
            if ($port) {
                return $port->device;
            }
        } catch (InvalidIpException $e) {
            //
        } catch (ModelNotFoundException $e) {
            //
        }

        try {
            $ipv6 = new IPv6($ip);
            $port = Ipv6Address::where('ipv6_address', $ipv6->uncompressed())
                ->with(['port', 'port.device'])
                ->firstOrFail()->port;
            if ($port) {
                return $port->device;
            }
        } catch (InvalidIpException $e) {
            //
        } catch (ModelNotFoundException $e) {
            //
        }

        return null;
    }

    /**
     * Get the display name of this device (hostname) unless force_ip_to_sysname is set
     * and hostname is an IP and sysName is set
     *
     * @return string
     */
    public function displayName()
    {
        if (\LibreNMS\Config::get('force_ip_to_sysname') && $this->sysName && IP::isValid($this->hostname)) {
            return $this->sysName;
        }

        return $this->hostname;
    }

    public function name()
    {
        $displayName = $this->displayName();
        if ($this->sysName !== $displayName) {
            return $this->sysName;
        } elseif ($this->hostname !== $displayName && $this->hostname !== $this->ip) {
            return $this->hostname;
        }

        return '';
    }

    public function isUnderMaintenance()
    {
        $query = AlertSchedule::isActive()
            ->join('alert_schedulables', 'alert_schedule.schedule_id', 'alert_schedulables.schedule_id')
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('alert_schedulable_type', 'device')
                        ->where('alert_schedulable_id', $this->device_id);
                });

                if ($this->groups) {
                    $query->orWhere(function ($query) {
                        $query->where('alert_schedulable_type', 'device_group')
                            ->whereIn('alert_schedulable_id', $this->groups->pluck('id'));
                    });
                }

                if ($this->location) {
                    $query->orWhere(function ($query) {
                        $query->where('alert_schedulable_type', 'location')
                            ->where('alert_schedulable_id', $this->location->id);
                    });
                }
            });

        return $query->exists();
    }

    public function loadOs($force = false)
    {
        $yaml_file = base_path('/includes/definitions/' . $this->os . '.yaml');

        if ((!\LibreNMS\Config::getOsSetting($this->os, 'definition_loaded') || $force) && file_exists($yaml_file)) {
            $os = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($yaml_file));

            \LibreNMS\Config::set("os.$this->os", array_replace_recursive($os, \LibreNMS\Config::get("os.$this->os", [])));
            \LibreNMS\Config::set("os.$this->os.definition_loaded", true);
        }
    }

    /**
     * Load all OS, optionally load just the OS used by existing devices
     * Default cache time is 1 day. Controlled by os_def_cache_time.
     *
     * @param bool $existing Only load OS that have existing OS in the database
     * @param bool $cached Load os definitions from the cache file
     */
    public static function loadAllOs($existing = false, $cached = true)
    {
        $install_dir = \LibreNMS\Config::get('install_dir');
        $cache_file = $install_dir . '/cache/os_defs.cache';
        if ($cached && is_file($cache_file) && (time() - filemtime($cache_file) < \LibreNMS\Config::get('os_def_cache_time'))) {
            // Cached
            $os_defs = unserialize(file_get_contents($cache_file));
            if ($existing) {
                // remove unneeded os
                $os_defs = array_diff_key($os_defs, self::distinct('os')->get('os')->toArray());
            }
            \LibreNMS\Config::set('os', array_replace_recursive($os_defs, \LibreNMS\Config::get('os')));
        } else {
            // load from yaml
            if ($existing) {
                $os_list = [];
                foreach (self::distinct('os')->get('os')->toArray() as $os) {
                    $os_list[] = $install_dir . '/includes/definitions/' . $os['os'] . '.yaml';
                }
            } else {
                $os_list = glob($install_dir . '/includes/definitions/*.yaml');
            }
            foreach ($os_list as $file) {
                if (is_readable($file)) {
                    $tmp = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($file));
                    \LibreNMS\Config::set("os.{$tmp['os']}", array_replace_recursive($tmp, \LibreNMS\Config::get("os.{$tmp['os']}", [])));
                }
            }
        }
    }

    /**
     * Get the shortened display name of this device.
     * Length is always overridden by shorthost_target_length.
     *
     * @param int $length length to shorten to, will not break up words so may be longer
     * @return string
     */
    public function shortDisplayName($length = 12)
    {
        $name = $this->displayName();

        // IP addresses should not be shortened
        if (IP::isValid($name)) {
            return $name;
        }

        $length = \LibreNMS\Config::get('shorthost_target_length', $length);
        if ($length < strlen($name)) {
            $take = substr_count($name, '.', 0, $length) + 1;
            return implode('.', array_slice(explode('.', $name), 0, $take));
        }

        return $name;
    }

    /**
     * Check if user can access this device.
     *
     * @param User $user
     * @return bool
     */
    public function canAccess($user)
    {
        if (!$user) {
            return false;
        }

        if ($user->hasGlobalRead()) {
            return true;
        }

        return Permissions::canAccessDevice($this->device_id, $user->user_id);
    }

    public function formatUptime($short = false)
    {
        return Time::formatInterval($this->uptime, $short);
    }

    /**
     * @return string
     */
    public function logo()
    {
        $base_name = pathinfo($this->icon, PATHINFO_FILENAME);
        $options = [
            "images/logos/$base_name.svg",
            "images/logos/$base_name.png",
            "images/os/$base_name.svg",
            "images/os/$base_name.png",
        ];

        foreach ($options as $file) {
            if (is_file(public_path()."/$file")) {
                return asset($file);
            }
        }

        return asset('images/os/generic.svg');
    }

    /**
     * Get list of enabled graphs for this device.
     *
     * @return \Illuminate\Support\Collection
     */
    public function graphs()
    {
        return DB::table('device_graphs')
            ->where('device_id', $this->device_id)
            ->pluck('graph');
    }

    /**
     * Update the max_depth field based on parents
     * Performs SQL query, so make sure all parents are saved first
     *
     * @param int $exclude exclude a device_id from being considered (used for deleting)
     */
    public function updateMaxDepth($exclude = null)
    {
        // optimize for memory instead of time
        $query = $this->parents()->getQuery();
        if (!is_null($exclude)) {
            $query->where('device_id', '!=', $exclude);
        }

        $count = $query->count();
        if ($count === 0) {
            if ($this->children()->count() === 0) {
                $this->max_depth = 0; // no children or parents
            } else {
                $this->max_depth = 1; // has children
            }
        } else {
            $parents_max_depth = $query->max('max_depth');
            $this->max_depth = $parents_max_depth + 1;
        }

        $this->save();
    }

    /**
     * Device dependency check to see if this node is standalone or not.
     * Standalone is a special case where the device has no parents or children and is denoted by a max_depth of 0
     *
     * Only checks on root nodes (where max_depth is 1 or 0)
     *
     */
    public function validateStandalone()
    {
        if ($this->max_depth === 0 && $this->children()->count() > 0) {
            $this->max_depth = 1;  // has children
        } elseif ($this->max_depth === 1 && $this->parents()->count() === 0) {
            $this->max_depth = 0;  // no children or parents
        }

        $this->save();
    }

    public function getAttrib($name)
    {
        return $this->attribs->pluck('attrib_value', 'attrib_type')->get($name);
    }

    public function setAttrib($name, $value)
    {
        $attrib = $this->attribs->first(function ($item) use ($name) {
            return $item->attrib_type === $name;
        });

        if (!$attrib) {
            $attrib = new DeviceAttrib(['attrib_type' => $name]);
            $this->attribs->push($attrib);
        }

        $attrib->attrib_value = $value;
        return (bool)$this->attribs()->save($attrib);
    }

    public function forgetAttrib($name)
    {
        $attrib_index = $this->attribs->search(function ($attrib) use ($name) {
            return $attrib->attrib_type === $name;
        });

        if ($attrib_index !== false) {
            $deleted=(bool)$this->attribs->get($attrib_index)->delete();
            // only forget the attrib_index after delete, otherwise delete() will fail fatally with:
            // Symfony\\Component\\Debug\Exception\\FatalThrowableError(code: 0):  Call to a member function delete() on null
            $this->attribs->forget($attrib_index);
            return $deleted;
        }

        return false;
    }

    public function getAttribs()
    {
        return $this->attribs->pluck('attrib_value', 'attrib_type')->toArray();
    }

    // ---- Accessors/Mutators ----

    public function getIconAttribute($icon)
    {
        $this->loadOs();
        return Str::start(Url::findOsImage($this->os, $this->features, $icon), 'images/os/');
    }

    public function getIpAttribute($ip)
    {
        if (empty($ip)) {
            return null;
        }
        // @ suppresses warning, inet_ntop() returns false if it fails
        return @inet_ntop($ip) ?: null;
    }

    public function setIpAttribute($ip)
    {
        $this->attributes['ip'] = inet_pton($ip);
    }

    public function setStatusAttribute($status)
    {
        $this->attributes['status'] = (int)$status;
    }

    // ---- Query scopes ----

    public function scopeIsUp($query)
    {
        return $query->where([
            ['status', '=', 1],
            ['ignore', '=', 0],
            ['disable_notify', '=', 0],
            ['disabled', '=', 0]
        ]);
    }

    public function scopeIsActive($query)
    {
        return $query->where([
            ['ignore', '=', 0],
            ['disabled', '=', 0]
        ]);
    }

    public function scopeIsDown($query)
    {
        return $query->where([
            ['status', '=', 0],
            ['disable_notify', '=', 0],
            ['ignore', '=', 0],
            ['disabled', '=', 0]
        ]);
    }

    public function scopeIsIgnored($query)
    {
        return $query->where([
            ['ignore', '=', 1],
            ['disabled', '=', 0]
        ]);
    }

    public function scopeNotIgnored($query)
    {
        return $query->where([
            ['ignore', '=', 0]
        ]);
    }

    public function scopeIsDisabled($query)
    {
        return $query->where([
            ['disabled', '=', 1]
        ]);
    }

    public function scopeIsDisableNotify($query)
    {
        return $query->where([
            ['disable_notify', '=', 1]
        ]);
    }

    public function scopeIsNotDisabled($query)
    {
        return $query->where([
            ['disable_notify', '=', 0],
            ['disabled', '=', 0]
        ]);
    }

    public function scopeWhereUptime($query, $uptime, $modifier = '<')
    {
        return $query->where([
            ['uptime', '>', 0],
            ['uptime', $modifier, $uptime]
        ]);
    }

    public function scopeCanPing(Builder $query)
    {
        return $query->where('disabled', 0)
            ->leftJoin('devices_attribs', function (JoinClause $query) {
                $query->on('devices.device_id', 'devices_attribs.device_id')
                    ->where('devices_attribs.attrib_type', 'override_icmp_disable');
            })
            ->where(function (Builder $query) {
                $query->whereNull('devices_attribs.attrib_value')
                    ->orWhere('devices_attribs.attrib_value', '!=', 'true');
            });
    }

    public function scopeHasAccess($query, User $user)
    {
        return $this->hasDeviceAccess($query, $user);
    }

    public function scopeInDeviceGroup($query, $deviceGroup)
    {
        return $query->whereIn($query->qualifyColumn('device_id'), function ($query) use ($deviceGroup) {
            $query->select('device_id')
                ->from('device_group_device')
                ->where('device_group_id', $deviceGroup);
        });
    }

    // ---- Define Relationships ----

    public function alerts()
    {
        return $this->hasMany(\App\Models\Alert::class, 'device_id');
    }

    public function attribs()
    {
        return $this->hasMany(\App\Models\DeviceAttrib::class, 'device_id');
    }

    public function alertSchedules()
    {
        return $this->morphToMany(\App\Models\AlertSchedule::class, 'alert_schedulable', 'alert_schedulables', 'schedule_id', 'schedule_id');
    }

    public function applications()
    {
        return $this->hasMany(\App\Models\Application::class, 'device_id');
    }

    public function bgppeers()
    {
        return $this->hasMany(\App\Models\BgpPeer::class, 'device_id');
    }

    public function cefSwitching()
    {
        return $this->hasMany(\App\Models\CefSwitching::class, 'device_id');
    }

    public function children()
    {
        return $this->belongsToMany(\App\Models\Device::class, 'device_relationships', 'parent_device_id', 'child_device_id');
    }

    public function components()
    {
        return $this->hasMany(\App\Models\Component::class, 'device_id');
    }

    public function eventlogs()
    {
        return $this->hasMany(\App\Models\Eventlog::class, 'device_id', 'device_id');
    }

    public function groups()
    {
        return $this->belongsToMany(\App\Models\DeviceGroup::class, 'device_group_device', 'device_id', 'device_group_id');
    }

    public function ipv4()
    {
        return $this->hasManyThrough(\App\Models\Ipv4Address::class, \App\Models\Port::class, 'device_id', 'port_id', 'device_id', 'port_id');
    }

    public function ipv6()
    {
        return $this->hasManyThrough(\App\Models\Ipv6Address::class, \App\Models\Port::class, 'device_id', 'port_id', 'device_id', 'port_id');
    }

    public function location()
    {
        return $this->belongsTo(\App\Models\Location::class, 'location_id', 'id');
    }

    public function ospfInstances()
    {
        return $this->hasMany(\App\Models\OspfInstance::class, 'device_id');
    }

    public function packages()
    {
        return $this->hasMany(\App\Models\Package::class, 'device_id', 'device_id');
    }

    public function parents()
    {
        return $this->belongsToMany(\App\Models\Device::class, 'device_relationships', 'child_device_id', 'parent_device_id');
    }

    public function perf()
    {
        return $this->hasMany(\App\Models\DevicePerf::class, 'device_id');
    }

    public function ports()
    {
        return $this->hasMany(\App\Models\Port::class, 'device_id', 'device_id');
    }

    public function portsFdb()
    {
        return $this->hasMany(\App\Models\PortsFdb::class, 'device_id', 'device_id');
    }

    public function portsNac()
    {
        return $this->hasMany(\App\Models\PortsNac::class, 'device_id', 'device_id');
    }

    public function processors()
    {
        return $this->hasMany(\App\Models\Processor::class, 'device_id');
    }

    public function rules()
    {
        return $this->belongsToMany(\App\Models\AlertRule::class, 'alert_device_map', 'device_id', 'rule_id');
    }

    public function sensors()
    {
        return $this->hasMany(\App\Models\Sensor::class, 'device_id');
    }

    public function services()
    {
        return $this->hasMany(\App\Models\Service::class, 'device_id');
    }

    public function storage()
    {
        return $this->hasMany(\App\Models\Storage::class, 'device_id');
    }

    public function mempools()
    {
        return $this->hasMany(\App\Models\Mempool::class, 'device_id');
    }

    public function mplsLsps()
    {
        return $this->hasMany(\App\Models\MplsLsp::class, 'device_id');
    }

    public function mplsLspPaths()
    {
        return $this->hasMany(\App\Models\MplsLspPath::class, 'device_id');
    }

    public function mplsSdps()
    {
        return $this->hasMany(\App\Models\MplsSdp::class, 'device_id');
    }

    public function mplsServices()
    {
        return $this->hasMany(\App\Models\MplsService::class, 'device_id');
    }

    public function mplsSaps()
    {
        return $this->hasMany(\App\Models\MplsSap::class, 'device_id');
    }

    public function mplsSdpBinds()
    {
        return $this->hasMany(\App\Models\MplsSdpBind::class, 'device_id');
    }

    public function mplsTunnelArHops()
    {
        return $this->hasMany(\App\Models\MplsTunnelArHop::class, 'device_id');
    }

    public function mplsTunnelCHops()
    {
        return $this->hasMany(\App\Models\MplsTunnelCHop::class, 'device_id');
    }

    public function syslogs()
    {
        return $this->hasMany(\App\Models\Syslog::class, 'device_id', 'device_id');
    }

    public function users()
    {
        // FIXME does not include global read
        return $this->belongsToMany(\App\Models\User::class, 'devices_perms', 'device_id', 'user_id');
    }

    public function vminfo()
    {
        return $this->hasMany(\App\Models\Vminfo::class, 'device_id');
    }

    public function vrfLites()
    {
        return $this->hasMany(\App\Models\VrfLite::class, 'device_id');
    }

    public function vrfs()
    {
        return $this->hasMany(\App\Models\Vrf::class, 'device_id');
    }

    public function wirelessSensors()
    {
        return $this->hasMany(\App\Models\WirelessSensor::class, 'device_id');
    }
}
