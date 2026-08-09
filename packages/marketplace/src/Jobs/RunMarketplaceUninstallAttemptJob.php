<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Core\Actions\DeleteExtensionDataAction;
use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\NotifyMarketplaceUninstallCompletedAction;
use Capell\Marketplace\Actions\PropagateMarketplaceRuntimeStateAction;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Override;
use RuntimeException;
use Throwable;

/**
 * Take an installed extension off this site, on a worker rather than inside the
 * request that asked for it.
 *
 * The order is the mirror image of an install, and it has to be: the extension
 * tears itself down first, while its own code is still on disk and loadable,
 * and only then is the package removed. Running `composer remove` first would
 * delete the uninstall hook that was supposed to run and leave the extension's
 * tables, settings and registrations behind with nothing left that knows how to
 * clean them up.
 *
 * That ordering is also what makes a failed uninstall different from a failed
 * install. Restoring composer.json, composer.lock and vendor/ puts the *code*
 * back; it does not un-run a lifecycle hook, any more than it un-runs a
 * migration. So this job tracks whether the lifecycle already ran, and when it
 * did, the operator is told that the code came back and the extension's own
 * state did not — a reinstall, not a retry.
 */
final class RunMarketplaceUninstallAttemptJob extends AbstractMarketplaceOperationJob
{
    /**
     * Whether the extension has already torn itself down.
     *
     * The single fact every honesty claim below is measured against, so it is
     * set at the moment the lifecycle returns rather than inferred afterwards
     * from a registry that the rollback is about to change under it.
     */
    private bool $lifecycleApplied = false;

    #[Override]
    protected function progressTotal(): int
    {
        return 5;
    }

    #[Override]
    protected function stageProgress(MarketplaceInstallFailureStage $stage): int
    {
        return match ($stage) {
            MarketplaceInstallFailureStage::Preflight,
            MarketplaceInstallFailureStage::Queue => 0,
            MarketplaceInstallFailureStage::Lifecycle => 1,
            MarketplaceInstallFailureStage::Composer => 2,
            MarketplaceInstallFailureStage::PackageDiscovery,
            MarketplaceInstallFailureStage::Migration => 3,
            MarketplaceInstallFailureStage::HealthCheck => 4,
            MarketplaceInstallFailureStage::Notification,
            MarketplaceInstallFailureStage::DeploymentHandoff => 5,
        };
    }

    #[Override]
    protected function preparesBeforeComposer(): bool
    {
        return true;
    }

    /**
     * The extension's own uninstall, run before Composer touches anything.
     *
     * `delete: false` is not a downgrade of the operator's choice — it is where
     * the choice is honoured. Passing `delete: true` would have the core action
     * run its own `composer remove` inline, outside this job's snapshot,
     * timeout budget and rollback. The removal is a stage of its own here
     * precisely so that it gets all three.
     */
    #[Override]
    protected function prepareOperation(MarketplaceInstallAttempt $attempt): void
    {
        $options = $this->options($attempt);

        if (! $options->runLifecycle) {
            return;
        }

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_lifecycle_started', MarketplaceInstallFailureStage::Lifecycle, [
            'composer_name' => $attempt->composer_name,
            'delete_package' => $options->deletePackage,
            'delete_data' => $options->deleteData,
        ]);

        foreach ($this->packageNames($attempt) as $packageName) {
            if (! CapellCore::hasPackage($packageName)) {
                throw new RuntimeException(sprintf('Package [%s] is not known to Capell, so it cannot be uninstalled.', $packageName));
            }

            UninstallPackageAction::run(
                CapellCore::getPackage($packageName),
                delete: false,
                deleteData: $options->deleteData,
            );

            $this->lifecycleApplied = true;
        }

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_lifecycle_completed', MarketplaceInstallFailureStage::Lifecycle);
    }

    /**
     * An operator who kept the package files asked for no Composer run at all,
     * and that is a completed uninstall rather than a truncated one.
     */
    #[Override]
    protected function shouldSkipComposerStage(MarketplaceInstallAttempt $attempt): bool
    {
        return ! $this->options($attempt)->deletePackage;
    }

    #[Override]
    protected function composerSkippedTranslationKey(): string
    {
        return 'timeline_composer_skipped_package_retained';
    }

    /**
     * `composer remove`, gated exactly as the admin panel's in-request removal
     * was.
     *
     * Being on a worker is not an exemption. CAPELL_SERVER_SIDE_TOOLING gates
     * *unattended Composer writes an HTTP request set in motion*, and a queued
     * job is still one of those — the operator clicked a button and walked
     * away. Queueing it made the write survivable, not permitted.
     */
    #[Override]
    protected function runComposer(MarketplaceComposerRunner $composer, MarketplaceInstallAttempt $attempt): MarketplaceComposerResultData
    {
        unset($composer);

        $outputs = [];

        foreach ($this->packageNames($attempt) as $packageName) {
            $result = RemovePackageAction::run(
                $packageName,
                requiresServerSideTooling: true,
                timeoutSeconds: $this->scriptReplayBudgetSeconds(),
            );
            $outputs[] = $result['output'];
        }

        return new MarketplaceComposerResultData(
            exitCode: 0,
            output: implode(PHP_EOL, $outputs),
            errorOutput: '',
        );
    }

    /**
     * Put this node back in step with the vendor directory the removal just
     * changed.
     *
     * This is where the gap RemovePackageAction deliberately left open is
     * closed for the queued path. That action clears the two manifests that
     * would name a removed package's providers, but Composer ran with
     * --no-scripts, so the application's own post-autoload-dump chain never
     * replayed — and Capell cannot enumerate what that chain does, because it
     * belongs to a different repository. reloadPackageRegistry() replays it,
     * which is the same treatment an install gets and the only way an
     * application that republishes assets or rebuilds caches on that hook is
     * left consistent after a removal.
     */
    #[Override]
    protected function applyOperation(MarketplaceInstallAttempt $attempt): void
    {
        $options = $this->options($attempt);

        if (! $options->runLifecycle && $options->deleteData) {
            foreach ($this->packageNames($attempt) as $packageName) {
                if (! CapellCore::hasPackage($packageName)) {
                    throw new RuntimeException(sprintf('Package [%s] is not known to Capell, so its data cannot be deleted.', $packageName));
                }

                DeleteExtensionDataAction::run(CapellCore::getPackage($packageName));
            }
        }

        $this->reloadPackageRegistry();
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_registry_reloaded', MarketplaceInstallFailureStage::PackageDiscovery);

        if (! $options->deletePackage) {
            return;
        }

        // Recorded, not asserted. An install can safely demand that the
        // registry now contains the package, because discovery is additive and
        // an absent entry means the install did not work. The reverse is not
        // symmetric: the registry has no per-package deregistration, so a stale
        // entry surviving a genuinely successful removal is possible — and
        // turning that into a throw would roll back an uninstall that worked.
        // Whether the site still boots without the package is a real question,
        // and the health check below is what answers it.
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_package_removed', MarketplaceInstallFailureStage::PackageDiscovery, [
            'composer_name' => $attempt->composer_name,
        ]);
    }

    /**
     * @param  array{rolled_back: bool, failure_reason: string, timeline_context: array<string, mixed>}  $rollback
     */
    #[Override]
    protected function resolveFailureType(
        array $rollback,
        MarketplaceInstallFailureStage $stage,
    ): ?MarketplaceInstallFailureType {
        return match (true) {
            // A rollback that did not complete still outranks everything else:
            // the operator's next action is to put the release root back by hand.
            ! $rollback['rolled_back'] => MarketplaceInstallFailureType::RollbackFailed,
            // Then the lifecycle, because it is the part that is *not* coming
            // back with the code and the operator cannot discover it from the
            // error.
            $this->lifecycleApplied => MarketplaceInstallFailureType::LifecycleException,
            $stage === MarketplaceInstallFailureStage::HealthCheck => MarketplaceInstallFailureType::HealthCheckFailed,
            default => null,
        };
    }

    /**
     * Correct what the operator is told when the code went back and the
     * extension's own teardown could not.
     *
     * The base rollback speaks only about composer.json, composer.lock and
     * vendor/. After the uninstall lifecycle has run, that sentence on its own
     * reads as "everything is as it was" — and an operator who believes it will
     * leave a site whose package is present, whose extension row is gone, and
     * whose data may have been deleted on purpose.
     *
     * @param  array{rolled_back: bool, failure_reason: string, timeline_context: array<string, mixed>}  $rollback
     * @return array{rolled_back: bool, failure_reason: string, timeline_context: array<string, mixed>}
     */
    #[Override]
    protected function decorateRollbackOutcome(
        MarketplaceInstallAttempt $attempt,
        array $rollback,
        MarketplaceInstallFailureStage $stage,
    ): array {
        if (! $this->lifecycleApplied) {
            return $rollback;
        }

        $originalReason = is_string($rollback['timeline_context']['error'] ?? null)
            ? $rollback['timeline_context']['error']
            : $rollback['failure_reason'];

        $options = $this->options($attempt);
        $reason = (string) __('capell-marketplace::marketplace.operations.rollback_lifecycle_retained', [
            'package' => $attempt->composer_name,
            'error' => $originalReason,
        ]);

        $context = [
            ...$rollback['timeline_context'],
            'reason' => $reason,
            'error' => $originalReason,
            'lifecycle_retained' => true,
            'deleted_data' => $options->deleteData,
        ];

        $this->recordEvent(
            $attempt,
            MarketplaceInstallAttemptEventLevel::Error,
            'timeline_rollback_lifecycle_retained',
            $stage,
            $context,
        );

        return [
            'rolled_back' => $rollback['rolled_back'],
            'failure_reason' => $reason,
            'timeline_context' => $context,
        ];
    }

    /**
     * Nothing here may throw: the attempt is already Succeeded and
     * ALLOWED_TRANSITIONS has no way out of it, so an escaping throwable would
     * turn a completed uninstall into an illegal-transition crash the queue
     * then retries — against a package that is no longer installed.
     */
    #[Override]
    protected function announceSucceededAttempt(MarketplaceInstallAttempt $attempt): void
    {
        $runtimeNotice = null;

        try {
            $runtimeNotice = PropagateMarketplaceRuntimeStateAction::run($attempt);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_runtime_propagation_failed', MarketplaceInstallFailureStage::Notification, [
                'reason' => $throwable->getMessage(),
            ]);
        }

        try {
            NotifyMarketplaceUninstallCompletedAction::run($attempt->refresh(), $runtimeNotice);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_notification_sent', MarketplaceInstallFailureStage::Notification);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_notification_failed', MarketplaceInstallFailureStage::Notification, [
                'reason' => $throwable->getMessage(),
            ]);
        }
    }

    private function options(MarketplaceInstallAttempt $attempt): MarketplaceUninstallOptionsData
    {
        return MarketplaceUninstallOptionsData::fromPayload($attempt->uninstall_options);
    }

    /** @return list<string> */
    private function packageNames(MarketplaceInstallAttempt $attempt): array
    {
        $packageNames = $this->options($attempt)->packageNames;

        return $packageNames !== [] ? $packageNames : [$attempt->composer_name];
    }
}
