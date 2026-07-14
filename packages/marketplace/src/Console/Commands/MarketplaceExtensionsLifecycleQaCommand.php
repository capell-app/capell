<?php

declare(strict_types=1);

namespace Capell\Marketplace\Console\Commands;

use Capell\Marketplace\Actions\RunMarketplaceExtensionsLifecycleQaAction;
use Capell\Marketplace\Data\MarketplaceExtensionLifecycleQaResultData;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class MarketplaceExtensionsLifecycleQaCommand extends Command
{
    /** @var string */
    protected $signature = 'marketplace:qa:extensions-lifecycle
        {--json : Output a compact JSON report}
        {--only= : Limit QA to one composer package}
        {--skip-delete : Skip extension-owned data deletion during uninstall}
        {--stop-on-failure : Stop after the first failed extension}
        {--acknowledge-beta : Explicitly allow beta extensions during lifecycle QA}
        {--dry-run : Resolve catalogue records and print the plan without installing, uninstalling, or deleting data}
    ';

    /** @var string */
    protected $description = 'Run local Marketplace extension install, uninstall, and data-deletion lifecycle QA.';

    public function handle(RunMarketplaceExtensionsLifecycleQaAction $qa): int
    {
        $results = $qa->handle(
            only: $this->only(),
            skipDelete: (bool) $this->option('skip-delete'),
            stopOnFailure: (bool) $this->option('stop-on-failure'),
            dryRun: (bool) $this->option('dry-run'),
            betaAcknowledged: (bool) $this->option('acknowledge-beta'),
        );

        $report = array_map(
            fn (MarketplaceExtensionLifecycleQaResultData $result): array => $result->toReportArray(),
            $results,
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $this->hasFailures($results),
                'count' => count($results),
                'extensions' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Extension', 'Composer package', 'Install', 'Uninstall', 'Delete', 'Failure reason'],
                array_map(fn (array $row): array => [
                    $row['extension'],
                    $row['composer_package'],
                    $row['install'],
                    $row['uninstall'],
                    $row['delete'],
                    $row['failure_reason'] ?? '',
                ], $report),
            );
        }

        return $this->hasFailures($results)
            ? CommandAlias::FAILURE
            : CommandAlias::SUCCESS;
    }

    private function only(): ?string
    {
        $only = $this->option('only');

        return is_string($only) && trim($only) !== ''
            ? trim($only)
            : null;
    }

    /**
     * @param  array<int, MarketplaceExtensionLifecycleQaResultData>  $results
     */
    private function hasFailures(array $results): bool
    {
        return array_any($results, fn (MarketplaceExtensionLifecycleQaResultData $result): bool => $result->failed());
    }
}
