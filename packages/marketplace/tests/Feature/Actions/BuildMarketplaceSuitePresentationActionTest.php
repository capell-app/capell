<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\BuildMarketplaceSuitePresentationAction;
use Capell\Marketplace\Data\ExtensionDetailData;

it('builds suite member pricing and savings only from comparable server prices', function (): void {
    $detail = ExtensionDetailData::fromApiResponse([
        'slug' => 'growth-suite',
        'name' => 'Growth Suite',
        'composer_name' => 'capell-app/growth-suite',
        'kind' => 'bundle',
        'is_paid' => true,
        'price_cents' => 7900,
        'currency' => 'GBP',
        'product' => ['bundle' => 'growth'],
        'dependencies' => [
            'requires' => ['capell-app/seo-suite', 'capell-app/forms'],
        ],
    ]);

    $presentation = BuildMarketplaceSuitePresentationAction::run($detail, [
        [
            'composer_name' => 'capell-app/seo-suite',
            'name' => 'SEO Suite',
            'price_cents' => 4900,
            'currency' => 'GBP',
        ],
        [
            'composer_name' => 'capell-app/forms',
            'name' => 'Forms',
            'price_cents' => 5900,
            'currency' => 'GBP',
        ],
    ]);

    expect($presentation)->not->toBeNull()
        ->and($presentation['bundle'])->toBe('growth')
        ->and($presentation['members'])->toHaveCount(2)
        ->and($presentation['members'][0])->toMatchArray([
            'name' => 'SEO Suite',
            'composer_name' => 'capell-app/seo-suite',
            'price' => '£49.00',
        ])
        ->and($presentation['combined_price'])->toBe('£79.00')
        ->and($presentation['member_total'])->toBe('£108.00')
        ->and($presentation['savings'])->toBe('£29.00');
});

it('does not invent savings when any member price or currency is unavailable', function (): void {
    $detail = ExtensionDetailData::fromApiResponse([
        'slug' => 'growth-suite',
        'name' => 'Growth Suite',
        'composer_name' => 'capell-app/growth-suite',
        'price_cents' => 7900,
        'currency' => 'GBP',
        'product' => ['bundle' => 'growth'],
        'dependencies' => ['requires' => ['capell-app/seo-suite']],
    ]);

    $presentation = BuildMarketplaceSuitePresentationAction::run($detail, []);

    expect($presentation)->not->toBeNull()
        ->and($presentation['members'][0]['name'])->toBe('capell-app/seo-suite')
        ->and($presentation['member_total'])->toBeNull()
        ->and($presentation['savings'])->toBeNull();
});
