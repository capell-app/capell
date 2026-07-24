<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Support\AdminRuntimeActivator;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $description = 'Sync Capell admin permissions against the registered Filament panel resources, pages and widgets';

    protected $signature = 'capell:admin-sync-permissions
        {--mode=install : Permission sync mode (install or upgrade)}';

    public function handle(): int
    {
        resolve(AdminRuntimeActivator::class)->activate();

        $mode = strtolower((string) $this->option('mode')) === 'upgrade'
            ? PermissionSyncMode::Upgrade
            : PermissionSyncMode::Install;

        SyncCapellPermissionsAction::run($mode);

        $this->info('Capell admin permissions synced.');

        return static::SUCCESS;
    }
}
