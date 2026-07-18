<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Upgrade;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordUpgradeSnapshotAction
{
    use AsFake;
    use AsObject;

    private const string UPDATE_ADVISORY_SNAPSHOTS_TABLE = 'marketplace_update_advisory_snapshots';

    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @param  array<int, array<string, mixed>>  $advisories
     * @param  array<string, mixed>  $metadata
     */
    public function handle(string $source, array $updates, array $advisories, array $metadata = [], ?string $capellVersion = null): void
    {
        if (! Schema::hasTable(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)) {
            return;
        }

        DB::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->insert([
            'source' => $source,
            'checked_at' => now(),
            'capell_version' => $capellVersion ?? CapellCore::getInstalledPrettyVersion('capell-app/capell'),
            'updates' => JsonCodec::encode($updates),
            'advisories' => JsonCodec::encode($advisories),
            'metadata' => JsonCodec::encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
