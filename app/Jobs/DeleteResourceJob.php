<?php

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\DeleteService;
use App\Actions\Service\StopService;
use App\Actions\Server\CleanupDocker;
use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class DeleteResourceJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
        public bool $deleteConfigurations,
        public bool $deleteVolumes,
        public bool $deleteImages,
        public bool $deleteConnectedNetworks
    ) {
    }

    public function handle()
    {
        try {
            $persistentStorages = collect();
            switch ($this->resource->type()) {
                case 'application':
                    $persistentStorages = $this->resource?->persistentStorages()?->get();
                    StopApplication::run($this->resource, previewDeployments: true);
                    break;
                case 'standalone-postgresql':
                case 'standalone-redis':
                case 'standalone-mongodb':
                case 'standalone-mysql':
                case 'standalone-mariadb':
                case 'standalone-keydb':
                case 'standalone-dragonfly':
                case 'standalone-clickhouse':
                    $persistentStorages = $this->resource?->persistentStorages()?->get();
                    StopDatabase::run($this->resource);
                    // TODO
                    // DBs do not have a network normally?
                    //if ($this->deleteConnectedNetworks) {
                    //  $this->resource?->delete_connected_networks($this->resource->uuid);
                    //    }
                    // }
                    // $server = data_get($this->resource, 'server');
                    // if ($this->deleteImages && $server) {
                    //    CleanupDocker::run($server, true);
                    // }
                    break;
                case 'service':
                    StopService::run($this->resource, true);
                    DeleteService::run($this->resource, $this->deleteConfigurations, $this->deleteVolumes, $this->deleteImages, $this->deleteConnectedNetworks);
                    break;
            }

            if ($this->deleteVolumes && $this->resource->type() !== 'service') {
                $this->resource?->delete_volumes($persistentStorages);
            }
            if ($this->deleteConfigurations) {
                $this->resource?->delete_configurations();
            }

            $server = data_get($this->resource, 'server');
            if ($this->deleteImages && $server) {
                CleanupDocker::run($server, true);
            }

            if ($this->deleteConnectedNetworks) {
                $this->resource?->delete_connected_networks($this->resource->uuid);
            }
        } catch (\Throwable $e) {
            send_internal_notification('ContainerStoppingJob failed with: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->resource->forceDelete();
            Artisan::queue('cleanup:stucked-resources');
        }
    }
}
