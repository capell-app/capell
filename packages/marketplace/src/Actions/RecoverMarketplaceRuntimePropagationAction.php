<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceRuntimePropagationStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecoverMarketplaceRuntimePropagationAction
{
    use AsFake;
    use AsObject;

    public const int TIMEOUT_SECONDS = 300;

    public static function isPending(MarketplaceInstallAttempt $attempt): bool
    {
        return $attempt->status === MarketplaceInstallIntentStatus::Succeeded
            && ($attempt->context['runtime_propagation'] ?? null) === MarketplaceRuntimePropagationStatus::Pending->value;
    }

    public static function hasExpired(MarketplaceInstallAttempt $attempt): bool
    {
        $deadline = $attempt->context['runtime_propagation_deadline_at'] ?? null;
        // Older pending records predate the explicit deadline. Completion is
        // their propagation start; missing timestamps require recovery now.
        $startedAt = ($attempt->completed_at ?? $attempt->created_at)?->getTimestamp() ?? 0;
        $deadline = is_int($deadline) ? $deadline : $startedAt + self::TIMEOUT_SECONDS;

        return now()->getTimestamp() >= $deadline;
    }

    public function handle(MarketplaceInstallAttempt $attempt, bool $interrupted = false): void
    {
        if (! self::isPending($attempt)) {
            return;
        }

        DB::transaction(function () use ($attempt, $interrupted): void {
            $locked = MarketplaceInstallAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if (self::isPending($locked) && ($interrupted || self::hasExpired($locked))) {
                $locked->forceFill([
                    'context' => [
                        ...($locked->context ?? []),
                        'runtime_propagation' => MarketplaceRuntimePropagationStatus::RecoveryRequired->value,
                    ],
                ])->save();
            }

            $attempt->setAttribute('context', $locked->context);
        });
    }
}
