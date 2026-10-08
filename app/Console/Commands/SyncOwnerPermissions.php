<?php

namespace App\Console\Commands;

use App\Services\OwnerPermissionSyncService;
use Illuminate\Console\Command;

class SyncOwnerPermissions extends Command
{
    protected $signature = 'permissions:sync-owner';

    protected $description = 'Assign every permission that is missing to the protected owner role';

    public function handle(OwnerPermissionSyncService $service)
    {
        $result = $service->sync();

        $this->info("Added {$result['added_count']} permission(s); owner role now has {$result['total']}.");
        foreach ($result['added'] as $name) {
            $this->line(" + {$name}");
        }

        return 0;
    }
}
