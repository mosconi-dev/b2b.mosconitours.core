<?php

namespace App\Console\Commands;

use App\Services\Rbac\PermissionRegistry;
use Illuminate\Console\Command;

class RbacSyncCommand extends Command
{
    protected $signature = 'rbac:sync {--prune : Delete permissions no longer defined in the registry}';

    protected $description = 'Sync RBAC permissions from the registry into the database';

    public function handle(PermissionRegistry $registry): int
    {
        $result = $registry->sync((bool) $this->option('prune'));

        $this->info("Synced {$result['synced']} permission(s) from the registry.");

        if ($result['orphans'] !== []) {
            $list = implode(', ', $result['orphans']);

            if ($result['pruned']) {
                $this->warn('Pruned '.count($result['orphans'])." orphan permission(s): {$list}");
            } else {
                $this->warn(count($result['orphans']).' orphan permission(s) not in the registry '
                    ."(run with --prune to remove): {$list}");
            }
        }

        return self::SUCCESS;
    }
}
