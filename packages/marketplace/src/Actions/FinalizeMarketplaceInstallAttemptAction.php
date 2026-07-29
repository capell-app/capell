<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FinalizeMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        MarketplaceComposerResultData $composerResult,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $composerResult): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAttempt->status === MarketplaceInstallIntentStatus::CancelRequested) {
                $reason = (string) __('capell-marketplace::marketplace.operations.cancelled_before_success_finalization');

                return TransitionMarketplaceInstallAttemptAction::run(
                    $lockedAttempt,
                    new MarketplaceInstallAttemptTransitionData(
                        toStatus: MarketplaceInstallIntentStatus::Cancelled,
                        failureReason: $reason,
                        failureStage: MarketplaceInstallFailureStage::Lifecycle,
                        composerResult: $composerResult,
                        outputExcerpt: $composerResult->output,
                        errorExcerpt: $composerResult->errorOutput,
                        timelineMessage: (string) __('capell-marketplace::marketplace.operations.timeline_cancelled_before_success_finalization'),
                        timelineContext: ['reason' => $reason],
                    ),
                );
            }

            if ($lockedAttempt->status !== MarketplaceInstallIntentStatus::Running) {
                return $lockedAttempt;
            }

            return TransitionMarketplaceInstallAttemptAction::run(
                $lockedAttempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Succeeded,
                    outputExcerpt: $composerResult->output,
                    errorExcerpt: $composerResult->errorOutput,
                ),
            );
        });
    }
}
