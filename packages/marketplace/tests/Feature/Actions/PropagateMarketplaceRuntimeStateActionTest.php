<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\PropagateMarketplaceRuntimeStateAction;
use Capell\Marketplace\Contracts\MarketplaceRuntimeRefresher;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Tests\Support\RecordingMarketplaceRuntimeRefresher;

beforeEach(function (): void {
    config()->set('capell.multi_node', false);
    config()->set('octane.server');
});

it('stays quiet on a plain single-process host', function (): void {
    $refresher = fakeMarketplaceRuntimeRefresher();
    $attempt = marketplaceRuntimeStateAttempt();

    $notice = PropagateMarketplaceRuntimeStateAction::run($attempt);

    expect($notice)->toBeNull()
        ->and($refresher->refreshCount)->toBe(0)
        ->and($attempt->events()->count())->toBe(0);
});

it('refreshes this node and still asks for an octane restart on a single-node host', function (): void {
    config()->set('octane.server', 'frankenphp');
    $refresher = fakeMarketplaceRuntimeRefresher();
    $attempt = marketplaceRuntimeStateAttempt();

    $notice = PropagateMarketplaceRuntimeStateAction::run($attempt);

    expect($refresher->refreshCount)->toBe(1)
        ->and($notice)->toBe(__('capell-marketplace::marketplace.operations.runtime_refresh_invoked'))
        ->and($attempt->events()->pluck('message')->all())
        ->toContain(__('capell-marketplace::marketplace.operations.timeline_runtime_refresh_invoked'));
});

it('refuses to claim the other nodes were refreshed on a multi-node host', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('octane.server', 'swoole');

    $refresher = fakeMarketplaceRuntimeRefresher();
    $attempt = marketplaceRuntimeStateAttempt();

    $notice = PropagateMarketplaceRuntimeStateAction::run($attempt);

    expect($refresher->refreshCount)->toBe(0)
        ->and($notice)->toBe(__('capell-marketplace::marketplace.operations.runtime_refresh_required_multi_node'))
        ->and($notice)->toContain('capell:runtime-refresh')
        ->and($attempt->events()->pluck('message')->all())
        ->toContain(__('capell-marketplace::marketplace.operations.timeline_runtime_refresh_required'));
});

it('asks for a manual refresh on a multi-node host even without octane', function (): void {
    config()->set('capell.multi_node', true);
    $refresher = fakeMarketplaceRuntimeRefresher();
    $attempt = marketplaceRuntimeStateAttempt();

    $notice = PropagateMarketplaceRuntimeStateAction::run($attempt);

    expect($refresher->refreshCount)->toBe(0)
        ->and($notice)->toBe(__('capell-marketplace::marketplace.operations.runtime_refresh_required_multi_node'));
});

it('keeps the install successful when the refresh itself fails', function (): void {
    config()->set('octane.server', 'frankenphp');
    app()->instance(MarketplaceRuntimeRefresher::class, new class implements MarketplaceRuntimeRefresher
    {
        public function refresh(): bool
        {
            throw new RuntimeException('Runtime refresh exploded.');
        }
    });

    $attempt = marketplaceRuntimeStateAttempt();
    $notice = PropagateMarketplaceRuntimeStateAction::run($attempt);

    expect($notice)->toBe(__('capell-marketplace::marketplace.operations.runtime_refresh_required_single_node'))
        ->and($attempt->events()->pluck('message')->all())
        ->toContain(__('capell-marketplace::marketplace.operations.timeline_runtime_refresh_required'));
});

function fakeMarketplaceRuntimeRefresher(): RecordingMarketplaceRuntimeRefresher
{
    $refresher = new RecordingMarketplaceRuntimeRefresher;

    app()->instance(MarketplaceRuntimeRefresher::class, $refresher);

    return $refresher;
}

function marketplaceRuntimeStateAttempt(array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/runtime-state-suite',
        'extension_slug' => 'runtime-state-suite',
        'extension_name' => 'Runtime State Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Succeeded,
        'composer_command' => 'composer require capell-app/runtime-state-suite:^1.0',
        'version_constraint' => '^1.0',
        'queued_at' => now(),
        ...$overrides,
    ]);
}
