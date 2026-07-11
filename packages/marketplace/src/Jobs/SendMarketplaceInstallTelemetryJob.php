<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SendMarketplaceInstallTelemetryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(private readonly int $installAttemptId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(MarketplaceClient $marketplace): void
    {
        $claimed = MarketplaceInstallAttempt::query()
            ->whereKey($this->installAttemptId)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('telemetry_status')
                    ->orWhere('telemetry_status', 'pending');
            })
            ->update([
                'telemetry_status' => 'syncing',
                'telemetry_attempted_at' => now(),
                'telemetry_failure' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($this->installAttemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        try {
            $marketplace->sendFreeInstallTelemetry($this->payload($attempt));
        } catch (Throwable $throwable) {
            $attempt->forceFill([
                'telemetry_status' => 'pending',
                'telemetry_failure' => $throwable->getMessage(),
            ])->save();

            $this->release(300);

            return;
        }

        $attempt->forceFill([
            'telemetry_status' => 'synced',
            'telemetry_synced_at' => now(),
            'telemetry_failure' => null,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function payload(MarketplaceInstallAttempt $attempt): array
    {
        return array_filter([
            'event_type' => 'install_intent',
            'slug' => $attempt->extension_slug,
            'extension_name' => $attempt->extension_name,
            'composer_name' => $attempt->composer_name,
            'version_constraint' => $attempt->version_constraint,
            'kind' => $attempt->kind,
            'status' => $attempt->status->value,
            'composer_command' => $attempt->composer_command,
            'app_url' => config('app.url'),
            'install_options' => $attempt->requested_options,
            'eligibility' => $attempt->eligibility,
            'context' => $this->sanitizedContext($attempt),
            'created_at' => $attempt->created_at?->toIso8601String(),
        ], fn (mixed $value): bool => ! in_array($value, [null, [], ''], true));
    }

    /** @return array<string, mixed> */
    private function sanitizedContext(MarketplaceInstallAttempt $attempt): array
    {
        $context = is_array($attempt->context) ? $attempt->context : [];
        unset($context['account_email'], $context['user_email']);

        return $context;
    }
}
