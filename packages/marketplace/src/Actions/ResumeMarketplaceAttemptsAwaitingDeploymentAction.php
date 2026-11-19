<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Picks up attempts whose Composer change was handed to the site's deploy
 * pipeline, once that deploy is live on this release.
 *
 * The evidence is the release itself, not a guess about the host: an install
 * has arrived when Capell can discover the package, and an update has arrived
 * when the installed version is no longer the one the update started from. An
 * attempt that has arrived is queued again with Composer marked as already
 * delivered, so the job runs verification and the package lifecycle without
 * touching vendor/. An attempt whose deploy never arrives fails after the
 * configured wait instead of holding the package forever.
 *
 * @method static array{resumed: int, expired: int} run()
 */
final class ResumeMarketplaceAttemptsAwaitingDeploymentAction
{
    use AsFake;
    use AsObject;

    public const string DELIVERED_CONTEXT_KEY = 'deployment_delivered';

    /**
     * Set on an attempt the moment it starts waiting for a deploy, and never
     * removed. Such an attempt is not retried in place: a retry would run
     * Composer on this site and skip the review the handoff exists for.
     */
    public const string AWAITED_CONTEXT_KEY = 'deployment_awaited';

    public function __construct(
        private readonly MarketplaceInstalledPackageVersionResolver $installedVersions,
    ) {}

    /** @return array{resumed: int, expired: int} */
    public function handle(): array
    {
        $resumed = 0;
        $expired = 0;

        MarketplaceInstallAttempt::query()
            ->where('status', MarketplaceInstallIntentStatus::AwaitingDeployment->value)
            ->lazyById()
            ->each(function (MarketplaceInstallAttempt $attempt) use (&$resumed, &$expired): void {
                if ($this->deploymentArrived($attempt)) {
                    $resumed += $this->resume($attempt) ? 1 : 0;

                    return;
                }

                if ($this->waitExpired($attempt)) {
                    $expired += $this->expire($attempt) ? 1 : 0;
                }
            });

        return ['resumed' => $resumed, 'expired' => $expired];
    }

    private function deploymentArrived(MarketplaceInstallAttempt $attempt): bool
    {
        if ($attempt->operation === MarketplaceOperationType::Update) {
            $fromVersion = $attempt->context['update_from_version'] ?? null;
            $installedVersion = $this->installedVersions->prettyVersion($attempt->composer_name);

            return is_string($installedVersion)
                && $installedVersion !== ''
                && is_string($fromVersion)
                && $installedVersion !== $fromVersion;
        }

        return PackageIsAvailableForLifecycleAction::run($attempt->composer_name);
    }

    private function resume(MarketplaceInstallAttempt $attempt): bool
    {
        $queued = DB::transaction(function () use ($attempt): ?MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedAttempt instanceof MarketplaceInstallAttempt
                || $lockedAttempt->status !== MarketplaceInstallIntentStatus::AwaitingDeployment) {
                return null;
            }

            $lockedAttempt->forceFill([
                'status' => MarketplaceInstallIntentStatus::Queued,
                'queued_at' => now(),
                'heartbeat_at' => now(),
                'current_stage' => MarketplaceInstallFailureStage::Queue->value,
                'context' => [...($lockedAttempt->context ?? []), self::DELIVERED_CONTEXT_KEY => true],
            ])->save();

            RecordMarketplaceInstallAttemptEventAction::run(
                attempt: $lockedAttempt,
                level: MarketplaceInstallAttemptEventLevel::Success,
                message: (string) __('capell-marketplace::marketplace.operations.timeline_deployment_arrived'),
                stage: MarketplaceInstallFailureStage::DeploymentHandoff,
            );

            return $lockedAttempt->refresh();
        });

        if (! $queued instanceof MarketplaceInstallAttempt) {
            return false;
        }

        DispatchMarketplaceOperationAttemptAction::run(
            attempt: $queued,
            queueConnection: (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database'),
            queue: (string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'),
        );

        return true;
    }

    private function waitExpired(MarketplaceInstallAttempt $attempt): bool
    {
        $waitingSince = $attempt->heartbeat_at ?? $attempt->created_at;

        return $waitingSince instanceof CarbonInterface
            && $waitingSince->lessThan(now()->subHours($this->waitHours()));
    }

    /**
     * Checked under the row lock, because a cancel or a reported deploy failure
     * can land between the query and here; either one already ended the wait.
     */
    private function expire(MarketplaceInstallAttempt $attempt): bool
    {
        return DB::transaction(function () use ($attempt): bool {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedAttempt instanceof MarketplaceInstallAttempt
                || $lockedAttempt->status !== MarketplaceInstallIntentStatus::AwaitingDeployment) {
                return false;
            }

            TransitionMarketplaceInstallAttemptAction::run(
                $lockedAttempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Failed,
                    failureReason: (string) __('capell-marketplace::marketplace.operations.deployment_wait_expired', [
                        'hours' => $this->waitHours(),
                    ]),
                    failureStage: MarketplaceInstallFailureStage::DeploymentHandoff,
                ),
            );

            return true;
        });
    }

    private function waitHours(): int
    {
        $hours = config('capell-marketplace.deployment_wait.hours', 72);

        return is_numeric($hours) && (int) $hours > 0 ? (int) $hours : 72;
    }
}
