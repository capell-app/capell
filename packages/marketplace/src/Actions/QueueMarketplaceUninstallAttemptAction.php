<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\AssertQueueConnectionReadyAction;
use Capell\Core\Exceptions\QueueConnectionNotReadyException;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceOperationVocabulary;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Put an uninstall on the queue, with the same guarantees an install gets.
 *
 * Deliberately the same shape as QueueMarketplaceInstallAttemptAction — same
 * cross-process lock, same duplicate guard, same queue-readiness assertion,
 * same preflight — because the thing being protected is the release root, and
 * it does not care which operation is about to rewrite it.
 *
 * The duplicate guard is across *all* operations rather than uninstalls alone,
 * which matters more here than anywhere else: an extension halfway through an
 * update has a vendor directory that is being rewritten under it, and starting
 * to tear that extension down at the same moment is the one combination this
 * system must never allow.
 */
final class QueueMarketplaceUninstallAttemptAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(
        string $composerName,
        string $extensionSlug,
        string $extensionName,
        string $kind,
        MarketplaceUninstallOptionsData $options,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        array $context = [],
        ?Authenticatable $user = null,
        ?string $idempotencyKey = null,
    ): MarketplaceInstallAttempt {
        $existingAttempt = $this->findIdempotentAttempt($idempotencyKey);

        if ($existingAttempt instanceof MarketplaceInstallAttempt) {
            return $existingAttempt;
        }

        // The database guard below cannot see an attempt another process has
        // decided to create but not yet written. The lock is named after the
        // package rather than the operation, because it is the package that can
        // only have one thing happening to it.
        $packageNames = $this->packageNames($composerName, $options);
        $lockPackageNames = $packageNames;
        sort($lockPackageNames);
        /** @var list<Lock> $locks */
        $locks = [];

        foreach ($lockPackageNames as $packageName) {
            $lock = Cache::lock('capell-marketplace:queue-install:' . hash('sha256', $packageName), 10);

            if (! $lock->get()) {
                foreach (array_reverse($locks) as $acquiredLock) {
                    $acquiredLock->release();
                }

                AssertNoActiveMarketplaceOperationAction::fail($packageName);
            }

            $locks[] = $lock;
        }

        try {
            return $this->queueWithLock(
                composerName: $composerName,
                extensionSlug: $extensionSlug,
                extensionName: $extensionName,
                kind: $kind,
                options: $options,
                actor: $actor,
                source: $source,
                context: $context,
                user: $user,
                idempotencyKey: $idempotencyKey,
            );
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function queueWithLock(
        string $composerName,
        string $extensionSlug,
        string $extensionName,
        string $kind,
        MarketplaceUninstallOptionsData $options,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        array $context,
        ?Authenticatable $user,
        ?string $idempotencyKey,
    ): MarketplaceInstallAttempt {
        $existingAttempt = $this->findIdempotentAttempt($idempotencyKey);

        if ($existingAttempt instanceof MarketplaceInstallAttempt) {
            return $existingAttempt;
        }

        $this->assertHostCanUninstall();

        $queueConnection = (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database');

        try {
            AssertQueueConnectionReadyAction::run($queueConnection);
        } catch (QueueConnectionNotReadyException $queueConnectionNotReadyException) {
            throw ValidationException::withMessages([
                'queue_connection' => $queueConnectionNotReadyException->getMessage(),
            ]);
        }

        foreach ($this->packageNames($composerName, $options) as $packageName) {
            AssertNoActiveMarketplaceOperationAction::run($packageName);
        }

        AssertMarketplaceUninstallAllowedAction::run($composerName, $options);

        $context['affected_package_names'] = $this->packageNames($composerName, $options);

        $attempt = CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
            extensionSlug: $extensionSlug,
            extensionName: $extensionName,
            composerName: $composerName,
            kind: $kind,
            status: MarketplaceInstallIntentStatus::Queued,
            betaAcknowledged: false,
            operation: MarketplaceOperationType::Uninstall,
            uninstallOptions: $options->toArray(),
            actor: $actor,
            source: $source,
            composerCommand: 'composer remove ' . $composerName,
            context: $context,
            idempotencyKey: $idempotencyKey,
            timelineMessage: MarketplaceOperationVocabulary::translate(MarketplaceOperationType::Uninstall, 'timeline_created'),
            timelineLevel: MarketplaceInstallAttemptEventLevel::Info,
            timelineStage: MarketplaceInstallFailureStage::Preflight,
        ), $user);

        $preflight = RunMarketplaceInstallPreflightChecksAction::run($attempt);

        if (! $preflight['passed']) {
            $firstFailure = collect($preflight['checks'])->first(fn (array $check): bool => $check['passed'] === false);
            $reason = is_array($firstFailure)
                ? (string) $firstFailure['message']
                : (string) __('capell-marketplace::marketplace.operations.preflight_failed');

            return TransitionMarketplaceInstallAttemptAction::run(
                $attempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Failed,
                    failureReason: $reason,
                    failureStage: MarketplaceInstallFailureStage::Preflight,
                ),
            );
        }

        return DispatchMarketplaceUninstallAttemptAction::run(
            attempt: $attempt,
            queueConnection: $queueConnection,
            queue: (string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'),
        );
    }

    /**
     * Refused before an attempt row exists, unlike the preflight below.
     *
     * A host that cannot run an automated operation at all has not failed this
     * uninstall — there was never an uninstall to fail. Recording an attempt
     * for it would put a permanent failed row on the operations page for a
     * question the operator should simply have been answered with the manual
     * instructions instead.
     */
    private function assertHostCanUninstall(): void
    {
        $capability = EvaluateMarketplaceEnvironmentReadinessAction::run()->capability;

        if ($capability === MarketplaceInstallCapability::Automated) {
            return;
        }

        throw ValidationException::withMessages([
            'capability' => __('capell-marketplace::marketplace.uninstalls.capability_unavailable', [
                'capability' => $capability->getLabel(),
            ]),
        ]);
    }

    private function findIdempotentAttempt(?string $idempotencyKey): ?MarketplaceInstallAttempt
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        return MarketplaceInstallAttempt::query()
            ->where('idempotency_key', hash('sha256', $idempotencyKey))
            ->first();
    }

    /** @return list<string> */
    private function packageNames(string $composerName, MarketplaceUninstallOptionsData $options): array
    {
        return $options->packageNames !== [] ? $options->packageNames : [$composerName];
    }
}
