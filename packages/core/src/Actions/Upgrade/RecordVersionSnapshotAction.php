<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Models\UpgradeLogEntry;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RecordVersionSnapshotAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, string>  $packageVersions
     * @return array<int, UpgradeLogEntry>
     */
    public function handle(array $packageVersions, bool $dryRun = false): array
    {
        if ($dryRun || $packageVersions === []) {
            return [];
        }

        $written = [];
        $packages = array_values(array_filter(array_keys($packageVersions), is_string(...)));
        $previousVersions = $this->latestVersions($packages);

        foreach ($packageVersions as $package => $version) {
            $fromVersion = $previousVersions[$package] ?? null;

            if ($fromVersion === $version) {
                continue;
            }

            $written[] = UpgradeLogEntry::query()->create([
                'type' => 'version_snapshot',
                'key' => $package,
                'package' => $package,
                'status' => 'recorded',
                'ran_at' => now(),
                'meta' => array_filter([
                    'from_version' => $fromVersion,
                    'to_version' => $version,
                ], static fn (mixed $value): bool => $value !== null),
            ]);
        }

        return $written;
    }

    /**
     * @param  list<string>  $packages
     * @return array<string, string>
     */
    private function latestVersions(array $packages): array
    {
        $latestRanAtByKey = UpgradeLogEntry::query()
            ->versionSnapshots()
            ->whereIn('key', $packages)
            ->select('key')
            ->selectRaw('MAX(ran_at) as latest_ran_at')
            ->groupBy('key');

        $latestSnapshotIds = UpgradeLogEntry::query()
            ->versionSnapshots()
            ->whereIn('capell_upgrade_log.key', $packages)
            ->joinSub($latestRanAtByKey, 'latest_version_snapshots', function (JoinClause $join): void {
                $join
                    ->on('capell_upgrade_log.key', '=', 'latest_version_snapshots.key')
                    ->on('capell_upgrade_log.ran_at', '=', 'latest_version_snapshots.latest_ran_at');
            })
            ->selectRaw('MAX(capell_upgrade_log.id) as id')
            ->groupBy('capell_upgrade_log.key')
            ->pluck('id')
            ->all();

        $rows = UpgradeLogEntry::query()
            ->whereIn('id', $latestSnapshotIds)
            ->get(['key', 'meta']);

        $versions = [];

        foreach ($rows as $row) {
            $version = $row->metaGet('to_version');

            if (is_string($version)) {
                $versions[$row->key] = $version;
            }
        }

        return $versions;
    }
}
