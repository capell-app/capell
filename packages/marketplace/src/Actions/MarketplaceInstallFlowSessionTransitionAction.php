<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class MarketplaceInstallFlowSessionTransitionAction
{
    use AsAction;

    /**
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        'pending' => ['redirected', 'expired', 'failed'],
        'redirected' => ['authorizing', 'expired', 'failed'],
        'authorizing' => ['returned', 'expired', 'failed'],
        'returned' => ['queued', 'expired', 'failed'],
        'queued' => ['completed', 'failed'],
        'completed' => [],
        'expired' => [],
        'failed' => ['authorizing', 'queued', 'expired'],
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        MarketplaceInstallFlowSession $session,
        MarketplaceInstallFlowSessionStatus $status,
        ?string $reason = null,
        array $metadata = [],
    ): MarketplaceInstallFlowSession {
        $currentStatus = $session->status->value;

        if ($currentStatus !== $status->value && ! in_array($status->value, self::ALLOWED_TRANSITIONS[$currentStatus] ?? [], true)) {
            throw new RuntimeException(sprintf(
                'Cannot transition Marketplace install flow session from [%s] to [%s].',
                $currentStatus,
                $status->value,
            ));
        }

        $transitionLog = array_values(array_filter($session->transition_log ?? [], is_array(...)));
        $transitionLog[] = [
            'from' => $currentStatus,
            'to' => $status->value,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
            'metadata' => $metadata,
        ];

        $failureReason = $this->failureReasonForStorage($status, $reason);

        $attributes = [
            'status' => $status,
            'transition_log' => $transitionLog,
            'failure_reason' => $failureReason,
            'last_error' => $status === MarketplaceInstallFlowSessionStatus::Failed ? $reason : null,
        ];

        if ($status === MarketplaceInstallFlowSessionStatus::Redirected && $session->redirected_at === null) {
            $attributes['redirected_at'] = now();
        }

        if ($status === MarketplaceInstallFlowSessionStatus::Returned && $session->returned_at === null) {
            $attributes['returned_at'] = now();
        }

        if ($status === MarketplaceInstallFlowSessionStatus::Queued && $session->queued_at === null) {
            $attributes['queued_at'] = now();
        }

        if ($status === MarketplaceInstallFlowSessionStatus::Completed && $session->completed_at === null) {
            $attributes['completed_at'] = now();
        }

        $session->forceFill($attributes)->save();

        Log::info('Marketplace install flow session transitioned.', [
            'flow_session_id' => $session->getKey(),
            'flow_id' => $session->remote_flow_id,
            'selected_composer_names' => collect($session->quoted_extensions ?? $session->selected_extensions ?? [])
                ->pluck('composer_name')
                ->filter()
                ->values()
                ->all(),
            'entitlement_ids' => array_values($session->remote_entitlement_ids ?? []),
            'state' => $status->value,
            'reason' => $reason,
        ]);

        return $session->refresh();
    }

    private function failureReasonForStorage(MarketplaceInstallFlowSessionStatus $status, ?string $reason): ?string
    {
        if (! in_array($status, [MarketplaceInstallFlowSessionStatus::Failed, MarketplaceInstallFlowSessionStatus::Expired], true)) {
            return null;
        }

        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return Str::limit(trim($reason), 240, '...');
    }
}
