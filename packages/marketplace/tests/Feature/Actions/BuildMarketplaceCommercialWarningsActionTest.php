<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\BuildMarketplaceCommercialWarningsAction;
use Carbon\CarbonImmutable;

it('returns expiring and expired purchase warnings without inventing warnings for healthy access', function (): void {
    CarbonImmutable::setTestNow('2026-08-05 12:00:00');

    $warnings = BuildMarketplaceCommercialWarningsAction::run([
        'purchases' => [
            [
                'id' => 'purchase-expiring',
                'name' => 'SEO Suite',
                'status' => 'active',
                'protected_updates' => true,
                'access_ends_at' => '2026-08-20T00:00:00Z',
            ],
            [
                'id' => 'purchase-expired',
                'name' => 'Forms Suite',
                'status' => 'active',
                'protected_updates' => false,
                'access_ends_at' => '2026-07-31T00:00:00Z',
            ],
            [
                'id' => 'purchase-healthy',
                'name' => 'Analytics Suite',
                'status' => 'active',
                'protected_updates' => true,
                'access_ends_at' => '2027-08-05T00:00:00Z',
            ],
        ],
    ]);

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0]->name)->toBe('SEO Suite')
        ->and($warnings[0]->status)->toBe('expiring_soon')
        ->and($warnings[0]->severity)->toBe('warning')
        ->and($warnings[1]->name)->toBe('Forms Suite')
        ->and($warnings[1]->status)->toBe('updates_expired')
        ->and($warnings[1]->severity)->toBe('danger');
});

it('ignores malformed or commercially healthy purchases', function (): void {
    expect(BuildMarketplaceCommercialWarningsAction::run([
        'purchases' => [
            'invalid',
            ['status' => 'expired'],
            ['name' => 'Healthy', 'status' => 'active', 'protected_updates' => true],
        ],
    ]))->toBe([]);
});
