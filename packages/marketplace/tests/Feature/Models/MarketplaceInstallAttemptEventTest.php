<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptEventAction;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallAttemptEvent;

it('records append-only ordered attempt timeline events with redacted context', function (): void {
    $attempt = marketplaceEventAttempt();

    RecordMarketplaceInstallAttemptEventAction::run(
        attempt: $attempt,
        level: MarketplaceInstallAttemptEventLevel::Info,
        message: 'Operation created',
        stage: MarketplaceInstallFailureStage::Preflight,
        context: ['license_key' => 'lic_secret', 'safe' => 'kept'],
    );

    RecordMarketplaceInstallAttemptEventAction::run(
        attempt: $attempt,
        level: MarketplaceInstallAttemptEventLevel::Error,
        message: 'Composer failed',
        stage: MarketplaceInstallFailureStage::Composer,
        outputExcerpt: 'token=abc123 {"token":"json-secret"} Bearer abc+/=',
    );

    $events = $attempt->refresh()->events;
    $firstEvent = expectPresent($events->first());
    $lastEvent = expectPresent($events->last());

    expect($events)->toHaveCount(2)
        ->and($firstEvent)->toBeInstanceOf(MarketplaceInstallAttemptEvent::class)
        ->and($firstEvent->level)->toBe(MarketplaceInstallAttemptEventLevel::Info)
        ->and($firstEvent->stage)->toBe(MarketplaceInstallFailureStage::Preflight)
        ->and($firstEvent->context)->toMatchArray([
            'license_key' => '[redacted]',
            'safe' => 'kept',
        ])
        ->and($lastEvent->output_excerpt)->toBe('token=[redacted] {"token":"[redacted]"} Bearer [redacted]');

    $firstEvent->forceFill(['message' => 'Mutated']);

    expect($firstEvent->save())->toBeFalse()
        ->and($firstEvent->delete())->toBeFalse()
        ->and($firstEvent->refresh()->message)->toBe('Operation created');
});

function marketplaceEventAttempt(array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'composer_command' => 'composer require capell-app/seo-suite:^2.1',
        'version_constraint' => '^2.1',
        'queued_at' => now(),
        ...$overrides,
    ]);
}
