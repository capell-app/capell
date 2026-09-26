<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Models\UpgradeLogEntry;
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
        $previousVersions = ReadLatestInstalledVersionsAction::run($packages);

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
}
