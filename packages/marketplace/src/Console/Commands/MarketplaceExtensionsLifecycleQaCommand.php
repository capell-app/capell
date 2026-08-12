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
        {--update-from= : Install this exact version before queueing an update; requires --only}
        {--skip-delete : Skip extension-owned data deletion during uninstall}
        {--stop-on-failure : Stop after the first failed extension}
        {--acknowledge-beta : Explicitly allow beta extensions during lifecycle QA}
        {--dry-run : Resolve catalogue records and print the plan without installing, uninstalling, or deleting data}
    ';

    /** @var string */
    protected $description = 'Run local Marketplace extension install, optional update, uninstall, and data-deletion lifecycle QA.';

    public function handle(RunMarketplaceExtensionsLifecycleQaAction $qa): int
    {
        $only = $this->only();
        $updateFrom = $this->updateFrom();

        if ($updateFrom !== null && $only === null) {
            $this->error((string) __('capell-marketplace::marketplace.qa.lifecycle.update_requires_only'));

            return CommandAlias::INVALID;
        }

        if ($updateFrom !== null && ! $this->isExactVersion($updateFrom)) {
            $this->error((string) __('capell-marketplace::marketplace.qa.lifecycle.update_requires_exact_version'));

            return CommandAlias::INVALID;
        }

        $results = RunMarketplaceExtensionsLifecycleQaAction::run(
            only: $only,
            skipDelete: (bool) $this->option('skip-delete'),
            stopOnFailure: (bool) $this->option('stop-on-failure'),
            dryRun: (bool) $this->option('dry-run'),
            betaAcknowledged: (bool) $this->option('acknowledge-beta'),
            updateFrom: $updateFrom,
        );

        if ($only !== null && $results === []) {
            $message = (string) __('capell-marketplace::marketplace.qa.lifecycle.extension_not_found', [
                'package' => $only,
            ]);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'ok' => false,
                    'count' => 0,
                    'extensions' => [],
                    'error' => $message,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return CommandAlias::FAILURE;
        }

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
                ['Extension', 'Composer package', 'Install', 'Update', 'Uninstall', 'Delete', 'Failure reason'],
                array_map(fn (array $row): array => [
                    $row['extension'],
                    $row['composer_package'],
                    $row['install'],
                    $row['update'],
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

    private function updateFrom(): ?string
    {
        $updateFrom = $this->option('update-from');

        return is_string($updateFrom) && trim($updateFrom) !== ''
            ? trim($updateFrom)
            : null;
    }

    private function isExactVersion(string $version): bool
    {
        return preg_match('/\Av?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/D', $version) === 1;
    }

    /**
     * @param  array<int, MarketplaceExtensionLifecycleQaResultData>  $results
     */
    private function hasFailures(array $results): bool
    {
        return array_any($results, fn (MarketplaceExtensionLifecycleQaResultData $result): bool => $result->failed());
    }
}
