<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Core\Actions\Upgrade\PublishPendingMigrationsAction;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunSettingsMigrationsAction;
use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\NotifyMarketplaceUpdateCompletedAction;
use Capell\Marketplace\Actions\PropagateMarketplaceRuntimeStateAction;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Exceptions\MarketplaceUpdateMigrationFailedException;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceMigrationLedger;
use Override;
use RuntimeException;
use Throwable;

/**
 * Take an installed extension to a newer version.
 *
 * Structurally identical to an install — same lock, same snapshot, same budget,
 * same health check before success is declared, same rollback — with one
 * difference that changes what a failure means: an update runs migrations, and
 * **restoring composer.json, composer.lock and vendor/ does not un-run a
 * migration**.
 *
 * So this job tracks whether the schema actually moved, and when it did, the
 * operator is told that the code was restored and the database was not. Saying
 * "rolled back" in that case would be false, and the false version is the one
 * that gets a site left broken quietly instead of loudly.
 */
final class RunMarketplaceUpdateAttemptJob extends AbstractMarketplaceOperationJob
{
    /**
     * What the migration repository looked like before this job ran anything.
     *
     * Captured rather than inferred from the migrate command's output: the
     * wording of "Nothing to migrate" is not a contract, and a partially applied
     * batch prints a mixture of both.
     */
    private ?MarketplaceMigrationLedger $migrationLedgerBefore = null;

    /** @var list<string> */
    private array $appliedMigrations = [];

    #[Override]
    protected function progressTotal(): int
    {
        return 6;
    }

    #[Override]
    protected function stageProgress(MarketplaceInstallFailureStage $stage): int
    {
        return match ($stage) {
            MarketplaceInstallFailureStage::Preflight,
            MarketplaceInstallFailureStage::Queue => 0,
            MarketplaceInstallFailureStage::Composer => 1,
            MarketplaceInstallFailureStage::PackageDiscovery => 2,
            MarketplaceInstallFailureStage::Lifecycle => 3,
            MarketplaceInstallFailureStage::Migration => 4,
            MarketplaceInstallFailureStage::HealthCheck => 5,
            MarketplaceInstallFailureStage::Notification,
            MarketplaceInstallFailureStage::DeploymentHandoff => 6,
        };
    }

    #[Override]
    protected function applyOperation(MarketplaceInstallAttempt $attempt): void
    {
        // Before the autoloader is reloaded, let alone before anything migrates.
        // This is the reference point every honesty claim below is measured
        // against, so it must be older than the first thing that could move.
        $this->migrationLedgerBefore = MarketplaceMigrationLedger::capture();

        $this->reloadPackageRegistry();
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_registry_reloaded', MarketplaceInstallFailureStage::PackageDiscovery);

        if (! CapellCore::hasPackage($attempt->composer_name)) {
            throw new RuntimeException(sprintf('Updated package [%s] was not discovered by Capell.', $attempt->composer_name));
        }

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_package_discovered', MarketplaceInstallFailureStage::PackageDiscovery, [
            'installed_version' => CapellCore::getInstalledPrettyVersion($attempt->composer_name),
        ]);

        $this->runMigrations($attempt);
    }

    /**
     * A migration failure lands on its own stage, and everything else keeps the
     * base class's reading.
     */
    #[Override]
    protected function failureStageFor(Throwable $throwable): MarketplaceInstallFailureStage
    {
        if ($throwable instanceof MarketplaceUpdateMigrationFailedException) {
            return MarketplaceInstallFailureStage::Migration;
        }

        return parent::failureStageFor($throwable);
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
            // A rollback that did not complete still outranks everything: the
            // operator's next action is to put the release root back by hand.
            ! $rollback['rolled_back'] => MarketplaceInstallFailureType::RollbackFailed,
            // Then the schema, because it is the part that is *not* coming back
            // on its own and the operator cannot discover it from the error.
            $this->schemaMoved() => MarketplaceInstallFailureType::SchemaAheadOfCode,
            $stage === MarketplaceInstallFailureStage::Migration => MarketplaceInstallFailureType::MigrationFailed,
            $stage === MarketplaceInstallFailureStage::HealthCheck => MarketplaceInstallFailureType::HealthCheckFailed,
            default => null,
        };
    }

    /**
     * Correct what the operator is told when the code went back and the schema
     * could not.
     *
     * The base rollback speaks only about composer.json, composer.lock and
     * vendor/. For an update that already migrated, that sentence on its own
     * reads as "everything is as it was", which is exactly the belief that gets
     * a site left in an inconsistent state without anyone looking at it.
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
        if (! $this->schemaMoved()) {
            return $rollback;
        }

        $originalReason = is_string($rollback['timeline_context']['error'] ?? null)
            ? $rollback['timeline_context']['error']
            : $rollback['failure_reason'];

        // Two different claims, because they are two different facts. "These
        // migrations ran and cannot be undone" is only sayable when the ledger
        // could be read; when it could not — a database outage is exactly the
        // failure that produces this path — the honest sentence is that we do
        // not know, not a specific claim about migrations that may not exist.
        $schemaVerified = $this->appliedMigrations !== [];

        $reason = $schemaVerified
            ? (string) __('capell-marketplace::marketplace.operations.rollback_schema_retained', [
                'package' => $attempt->composer_name,
                'migrations' => implode(', ', $this->appliedMigrations),
                'error' => $originalReason,
            ])
            : (string) __('capell-marketplace::marketplace.operations.rollback_schema_unverified', [
                'package' => $attempt->composer_name,
                'error' => $originalReason,
            ]);

        $context = [
            ...$rollback['timeline_context'],
            'reason' => $reason,
            'error' => $originalReason,
            'schema_retained' => true,
            'schema_verified' => $schemaVerified,
            'applied_migrations' => $this->appliedMigrations,
        ];

        $this->recordEvent(
            $attempt,
            MarketplaceInstallAttemptEventLevel::Error,
            $schemaVerified ? 'timeline_rollback_schema_retained' : 'timeline_rollback_schema_unverified',
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
     * turn a completed update into an illegal-transition crash the queue then
     * retries.
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
            NotifyMarketplaceUpdateCompletedAction::run($attempt->refresh(), $runtimeNotice);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_notification_sent', MarketplaceInstallFailureStage::Notification);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_notification_failed', MarketplaceInstallFailureStage::Notification, [
                'reason' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Publish and run whatever the new version of the package ships.
     *
     * These three core actions are global by construction — they take no package
     * and Laravel's migrator has no notion of which package a published
     * migration file came from — so this batch applies every pending migration
     * on the site, not only this extension's. That is the existing
     * `capell:upgrade` behaviour and is expected when an operator asked for the
     * update. It is not expected at 03:20, which is why
     * `QueueMarketplaceAutoUpdatesAction` declines to queue an unattended update
     * while another package has migrations waiting.
     *
     * This is the step that makes an update different from an install in the
     * one way that matters, so what it actually applied is recorded as it goes
     * rather than reconstructed afterwards — including when it fails part-way,
     * which is the case where the difference bites hardest.
     */
    private function runMigrations(MarketplaceInstallAttempt $attempt): void
    {
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_migrations_started', MarketplaceInstallFailureStage::Migration, [
            'composer_name' => $attempt->composer_name,
        ]);

        try {
            $published = PublishPendingMigrationsAction::run();

            throw_if(! $published->schemaPublished || ! $published->settingsPublished, MarketplaceUpdateMigrationFailedException::class, 'The pending migrations for this update could not be published into the host application.');

            $this->assertMigrationRunSucceeded(
                RunDatabaseMigrationsAction::run(),
                'Core database',
            );

            $this->assertMigrationRunSucceeded(
                RunPublishedDatabaseMigrationsAction::run(),
                'published database',
            );

            $this->assertMigrationRunSucceeded(
                RunSettingsMigrationsAction::run(),
                'settings',
            );
        } finally {
            // In a finally, not after the calls: a migration batch that threw
            // half way through has still applied whatever it got to, and that is
            // precisely the state the operator must not be told was rolled back.
            $this->recordAppliedMigrations();
        }

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_migrations_completed', MarketplaceInstallFailureStage::Migration, [
            'applied_migrations' => $this->appliedMigrations,
            'applied_count' => count($this->appliedMigrations),
        ]);
    }

    private function assertMigrationRunSucceeded(MigrationRunResult $result, string $kind): void
    {
        if ($result->exitCode === 0) {
            return;
        }

        throw new MarketplaceUpdateMigrationFailedException(sprintf(
            'The %s migrations for this update exited %d: %s',
            $kind,
            $result->exitCode,
            trim($result->output) ?: 'no output',
        ));
    }

    private function recordAppliedMigrations(): void
    {
        if (! $this->migrationLedgerBefore instanceof MarketplaceMigrationLedger) {
            return;
        }

        $this->appliedMigrations = MarketplaceMigrationLedger::capture()
            ->appliedSince($this->migrationLedgerBefore);
    }

    /**
     * Whether this update is known to have changed the database.
     *
     * A ledger that could not be read answers true rather than false. "We could
     * not check" is not evidence that nothing happened, and the cost of being
     * wrong in the two directions is not symmetric: an unnecessary warning wastes
     * an operator's afternoon, a missing one leaves a schema nobody knows moved.
     */
    private function schemaMoved(): bool
    {
        if ($this->appliedMigrations !== []) {
            return true;
        }

        return $this->migrationLedgerBefore instanceof MarketplaceMigrationLedger
            && MarketplaceMigrationLedger::capture()->changedSince($this->migrationLedgerBefore);
    }
}
