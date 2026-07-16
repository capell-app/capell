<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallAttemptEvent;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMarketplaceInstallDiagnosticBundleAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt): string
    {
        $attempt->loadMissing('events');

        $payload = [
            'operation' => [
                'id' => $attempt->getKey(),
                'status' => $attempt->status->value,
                'composer_name' => $attempt->composer_name,
                'extension_name' => $attempt->extension_name,
                'failure_type' => $attempt->failure_type,
                'failure_stage' => $attempt->failure_stage,
                'failure_reason' => $attempt->failure_reason,
                'queued_at' => $attempt->queued_at?->toIso8601String(),
                'started_at' => $attempt->started_at?->toIso8601String(),
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                'deployment' => $attempt->deployment,
                'diagnostic_context' => $attempt->diagnostic_context,
                'output_excerpt' => $attempt->output_excerpt,
                'error_excerpt' => $attempt->error_excerpt,
            ],
            'timeline' => $attempt->events->map(fn (MarketplaceInstallAttemptEvent $event): array => [
                'at' => $event->occurred_at->toIso8601String(),
                'level' => $event->level->value,
                'stage' => $event->stage?->value,
                'message' => $event->message,
                'context' => $event->context,
                'output_excerpt' => $event->output_excerpt,
            ])->values()->all(),
        ];

        return json_encode(
            RedactMarketplaceDiagnosticContextAction::run($payload),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
