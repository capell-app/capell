<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Models\UpgradeLogEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReadLatestInstalledVersionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>|null  $packages
     * @return array<string, string>
     */
    public function handle(?array $packages = null): array
    {
        if ($packages === []) {
            return [];
        }

        $latestRanAtByKey = UpgradeLogEntry::query()
            ->versionSnapshots()
            ->when($packages !== null, fn (Builder $query): Builder => $query->whereIn('key', $packages))
            ->select('key')
            ->selectRaw('MAX(ran_at) as latest_ran_at')
            ->groupBy('key');

        $latestSnapshotIds = UpgradeLogEntry::query()
            ->versionSnapshots()
            ->when($packages !== null, fn (Builder $query): Builder => $query->whereIn('capell_upgrade_log.key', $packages))
            ->joinSub($latestRanAtByKey, 'latest_version_snapshots', function (JoinClause $join): void {
                $join
                    ->on('capell_upgrade_log.key', '=', 'latest_version_snapshots.key')
                    ->on('capell_upgrade_log.ran_at', '=', 'latest_version_snapshots.latest_ran_at');
            })
            ->selectRaw('MAX(capell_upgrade_log.id) as id')
            ->groupBy('capell_upgrade_log.key')
            ->pluck('id')
            ->all();

        $versions = [];
        $rows = UpgradeLogEntry::query()
            ->whereIn('id', $latestSnapshotIds)
            ->orderBy('key')
            ->get(['key', 'meta']);

        foreach ($rows as $row) {
            $version = $row->metaGet('to_version');

            if (is_string($version)) {
                $versions[$row->key] = $version;
            }
        }

        return $versions;
    }
}
