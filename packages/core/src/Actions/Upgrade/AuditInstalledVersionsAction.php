<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\VersionAudit;
use Capell\Core\Models\UpgradeLogEntry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class AuditInstalledVersionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @var array<int, string>
     */
    private const RETIRED_LEDGER_PACKAGES = [
        'capell-app/installer',
        'capell-app/url-manager',
    ];

    /**
     * @param  array<string, string>  $composerVersions
     */
    public function handle(array $composerVersions): VersionAudit
    {
        $ledger = $this->lastKnownVersions();

        $composerOnly = array_values(array_diff(array_keys($composerVersions), array_keys($ledger)));
        $ledgerOnly = array_values(array_diff(
            array_keys($ledger),
            array_keys($composerVersions),
            self::RETIRED_LEDGER_PACKAGES,
        ));

        $downgrades = [];
        foreach ($composerVersions as $package => $composerVersion) {
            $ledgerVersion = $ledger[$package] ?? null;
            if ($ledgerVersion === null) {
                continue;
            }

            if (! $this->isComparableVersion($ledgerVersion)) {
                continue;
            }

            if ($this->isComparableVersion($composerVersion)) {
                if (version_compare($composerVersion, $ledgerVersion) < 0) {
                    $downgrades[$package] = ['from' => $ledgerVersion, 'to' => $composerVersion];
                }

                continue;
            }

            if (str_contains($composerVersion, 'dev')) {
                $downgrades[$package] = ['from' => $ledgerVersion, 'to' => $composerVersion];
            }
        }

        return new VersionAudit(
            composerOnly: $composerOnly,
            ledgerOnly: $ledgerOnly,
            downgrades: $downgrades,
        );
    }

    private function isComparableVersion(string $version): bool
    {
        return preg_match('/^v?\d+(\.\d+)*/i', $version) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function lastKnownVersions(): array
    {
        $rows = UpgradeLogEntry::query()
            ->versionSnapshots()
            ->orderBy('key')
            ->orderByDesc('ran_at')
            ->get(['key', 'meta']);

        $latest = [];

        foreach ($rows as $row) {
            if (array_key_exists($row->key, $latest)) {
                continue;
            }

            $version = $row->metaGet('to_version');
            if (is_string($version)) {
                $latest[$row->key] = $version;
            }
        }

        return $latest;
    }
}
