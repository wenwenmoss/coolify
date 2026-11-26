<?php

namespace App\Models;

use App\Traits\HasImageScans;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceDatabase extends BaseModel
{
    use HasFactory, HasImageScans, SoftDeletes;

    protected $fillable = [
        'service_id',
        'application_id',
        'name',
        'human_name',
        'description',
        'fqdn',
        'ports',
        'exposes',
        'status',
        'exclude_from_status',
        'image',
        'public_port',
        'is_public',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'last_online_at',
        'is_migrated',
        'custom_type',
        'public_port_timeout',
    ];

    protected $casts = [
        'exclude_from_status' => 'boolean',
        'is_removed_from_compose' => 'boolean',
        'is_public' => 'boolean',
        'is_log_drain_enabled' => 'boolean',
        'is_include_timestamps' => 'boolean',
        'is_gzip_enabled' => 'boolean',
        'is_stripprefix_enabled' => 'boolean',
        'public_port' => 'integer',
        'public_port_timeout' => 'integer',
    ];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
            $service->scheduledBackups()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->last_online_at = now();
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ServiceDatabase::whereRelation('service.environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for service databases owned by current team.
     * If you need all service databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return ServiceDatabase::whereRelation('service.environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all service databases owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return ServiceDatabase::ownedByCurrentTeam()->get();
        });
    }

    public function restart()
    {
        $container_id = $this->containerName();
        remote_process(['docker restart '.escapeshellarg($container_id)], $this->server());
    }

    public function isRunning()
    {
        return str($this->status)->contains('running');
    }

    public function isExited()
    {
        return str($this->status)->contains('exited');
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function isStripprefixEnabled()
    {
        return data_get($this, 'is_stripprefix_enabled', true);
    }

    public function isGzipEnabled()
    {
        return data_get($this, 'is_gzip_enabled', true);
    }

    public function type()
    {
        return 'service';
    }

    public function serviceType()
    {
        return null;
    }

    public function databaseType()
    {
        if (filled($this->custom_type)) {
            return 'standalone-'.$this->custom_type;
        }
        $image = str($this->image)->before(':');
        if ($image->contains('supabase/postgres')) {
            $finalImage = 'supabase/postgres';
        } elseif ($image->contains('timescale')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('pgvector')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('postgres') || $image->contains('postgis')) {
            $finalImage = 'postgresql';
        } else {
            $finalImage = $image;
        }

        return "standalone-$finalImage";
    }

    public function getServiceDatabaseUrl()
    {
        $port = $this->public_port;
        $realIp = $this->server()->ip;
        if ($this->server()->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return formatHostForUrl((string) $realIp).":{$port}";
    }

    public function team()
    {
        return $this->parentResource()?->team();
    }

    public function workdir()
    {
        return $this->parentResource()->workdir();
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function parentResource(): Service|Application|null
    {
        return $this->application_id !== null ? $this->application : $this->service;
    }

    public function server(): ?Server
    {
        $parent = $this->parentResource();

        return $parent instanceof Application ? $parent->destination?->server : $parent?->server;
    }

    /**
     * Stable across Git deployments, whose actual container names can contain a timestamp.
     */
    public function backupContainerName(): string
    {
        return $this->name.'-'.$this->parentResource()?->uuid;
    }

    public function containerName(): string
    {
        if ($this->application_id === null) {
            return $this->backupContainerName();
        }

        $containers = getCurrentApplicationContainerStatus($this->server(), $this->application)
            ->filter(function ($container) {
                $labels = format_docker_labels_to_json(data_get($container, 'Labels', []));

                return $labels->get('com.docker.compose.service') === $this->name
                    && ! filter_var($labels->get('com.docker.compose.oneoff', false), FILTER_VALIDATE_BOOLEAN);
            });
        $container = selectServingContainer($containers);
        if (! $container) {
            throw new \RuntimeException('The Compose database has no running production container.');
        }

        return data_get($container, 'Names');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function link(): ?string
    {
        return $this->parentResource()?->link();
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function isBackupSolutionAvailable()
    {
        if ($this->application_id !== null && $this->is_removed_from_compose) {
            return false;
        }

        return str($this->databaseType())->contains('mysql') ||
            str($this->databaseType())->contains('postgres') ||
            str($this->databaseType())->contains('postgis') ||
            str($this->databaseType())->contains('mariadb') ||
            str($this->databaseType())->contains('mongo') ||
            filled($this->custom_type);
    }
}
