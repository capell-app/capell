<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\SendMarketplaceInstallTelemetryJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;

it('coalesces telemetry dispatches for the same install attempt', function (): void {
    $job = new SendMarketplaceInstallTelemetryJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 1800]);
});

it('keeps free install telemetry pending when marketplace is unavailable', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    $attempt = marketplaceTelemetryAttempt();

    Http::fake([
        'https://marketplace.test/api/extensions/install-intents' => Http::response([
            'message' => 'Marketplace unavailable.',
        ], 503),
    ]);

    new SendMarketplaceInstallTelemetryJob((int) $attempt->getKey())->handle(resolve(MarketplaceClient::class));

    $attempt->refresh();

    expect($attempt->telemetry_status)->toBe('pending')
        ->and($attempt->telemetry_attempted_at)->not->toBeNull()
        ->and($attempt->telemetry_synced_at)->toBeNull()
        ->and($attempt->telemetry_failure)->toContain('Marketplace unavailable');
});

it('does not send local user or account email in free install telemetry', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    $attempt = marketplaceTelemetryAttempt([
        'context' => [
            'instance_id' => 'instance-123',
            'account_id' => 'acct_123',
            'account_email' => 'owner@example.test',
        ],
        'user_email' => 'admin@example.test',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/install-intents' => Http::response([], 202),
    ]);

    new SendMarketplaceInstallTelemetryJob((int) $attempt->getKey())->handle(resolve(MarketplaceClient::class));

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://marketplace.test/api/extensions/install-intents'
            && ! array_key_exists('user_email', $payload)
            && ! array_key_exists('account_email', $payload['context'] ?? [])
            && ($payload['context']['account_id'] ?? null) === 'acct_123';
    });
});

it('claims pending telemetry attempts atomically before sending', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    $attempt = marketplaceTelemetryAttempt(['telemetry_status' => 'syncing']);

    Http::fake();

    new SendMarketplaceInstallTelemetryJob((int) $attempt->getKey())->handle(resolve(MarketplaceClient::class));

    Http::assertNothingSent();
});

/** @param array<string, mixed> $overrides */
function marketplaceTelemetryAttempt(array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'composer_name' => 'capell-app/seo-suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::CommandFallback,
        'composer_command' => 'composer require capell-app/seo-suite:^1.0.0',
        'version_constraint' => '^1.0.0',
        'requested_options' => ['starter_content' => true],
        'eligibility' => ['state' => 'free_available'],
        'context' => ['connection_state' => 'not_connected'],
        'telemetry_status' => 'pending',
        ...$overrides,
    ]);
}
