<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;

it('keeps marketplace enum translation families complete and free of orphan labels', function (): void {
    $translations = require dirname(__DIR__, 2) . '/resources/lang/en/marketplace.php';

    foreach ([
        'event_levels' => MarketplaceInstallAttemptEventLevel::cases(),
        'failure_stages' => MarketplaceInstallFailureStage::cases(),
        'failure_types' => MarketplaceInstallFailureType::cases(),
        'install_statuses' => MarketplaceInstallIntentStatus::cases(),
    ] as $family => $cases) {
        $expectedKeys = collect($cases)->map->value->sort()->values()->all();
        $actualKeys = collect(array_keys($translations[$family]))->sort()->values()->all();

        expect($actualKeys)->toBe($expectedKeys);

        foreach ($cases as $case) {
            expect($case->getLabel())
                ->not->toBe($case->value)
                ->not->toStartWith('capell-marketplace::');
        }
    }
});

it('retains marketplace labels consumed through dynamic translation families', function (): void {
    foreach ([
        'capell-marketplace::marketplace.card.update_available',
        'capell-marketplace::marketplace.install.license_key_label',
        'capell-marketplace::marketplace.install.license_key_help',
    ] as $key) {
        expect(__($key))->not->toBe($key);
    }
});
