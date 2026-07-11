<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\ResolvePendingThemeInstallsAction;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CapellCore::clearPackages();
});

it('marks pending theme install intents as resolved when the package is installed', function (): void {
    $resolvedAt = CarbonImmutable::parse('2026-05-07 12:00:00');

    CapellCore::registerPackage('vendor/installed-theme', type: PackageTypeEnum::Theme);
    CapellCore::forcePackageInstalled('vendor/installed-theme');

    $intent = MarketplaceInstallIntent::query()->create([
        'composer_name' => 'vendor/installed-theme',
        'extension_slug' => 'installed-theme',
        'extension_name' => 'Installed Theme',
        'kind' => 'theme',
        'status' => MarketplaceInstallIntentStatus::Pending,
        'composer_command' => 'composer require vendor/installed-theme',
        'version_constraint' => null,
        'metadata' => null,
        'resolved_at' => null,
    ]);

    CarbonImmutable::setTestNow($resolvedAt);

    try {
        $resolvedCount = ResolvePendingThemeInstallsAction::run();
    } finally {
        CarbonImmutable::setTestNow();
    }

    $intent->refresh();
    $resolvedAtValue = expectPresent($intent->resolved_at);

    expect($resolvedCount)->toBe(1)
        ->and($intent->status)->toBe(MarketplaceInstallIntentStatus::Resolved)
        ->and($intent->resolved_at)->not->toBeNull()
        ->and($resolvedAtValue->equalTo($resolvedAt))->toBeTrue();
});

it('leaves pending theme install intents unresolved when the package is not installed', function (): void {
    $intent = MarketplaceInstallIntent::query()->create([
        'composer_name' => 'vendor/missing-theme',
        'extension_slug' => 'missing-theme',
        'extension_name' => 'Missing Theme',
        'kind' => 'theme',
        'status' => MarketplaceInstallIntentStatus::Pending,
        'composer_command' => 'composer require vendor/missing-theme',
        'version_constraint' => null,
        'metadata' => null,
        'resolved_at' => null,
    ]);

    $resolvedCount = ResolvePendingThemeInstallsAction::run();

    $intent->refresh();

    expect($resolvedCount)->toBe(0)
        ->and($intent->status)->toBe(MarketplaceInstallIntentStatus::Pending)
        ->and($intent->resolved_at)->toBeNull();
});
