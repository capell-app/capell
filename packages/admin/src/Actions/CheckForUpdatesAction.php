<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Actions\Upgrade\CheckCapellApiForUpdatesAction;
use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CheckForUpdatesAction
{
    use AsFake;
    use AsObject;

    private const string UPDATE_ADVISORY_SNAPSHOTS_TABLE = 'marketplace_update_advisory_snapshots';

    public function handle(): bool
    {
        if (CheckCapellApiForUpdatesAction::run()) {
            return true;
        }

        $this->recordLocalUpdateCheck();

        return true;
    }

    private function recordLocalUpdateCheck(): void
    {
        if (! Schema::hasTable(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)) {
            return;
        }

        DB::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->insert([
            'source' => 'admin',
            'checked_at' => now(),
            'capell_version' => CapellCore::getInstalledPrettyVersion('capell-app/capell'),
            'updates' => json_encode([], JSON_THROW_ON_ERROR),
            'advisories' => json_encode([], JSON_THROW_ON_ERROR),
            'metadata' => json_encode([
                'installed_packages' => ResolveInstalledComposerVersionsAction::run(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
