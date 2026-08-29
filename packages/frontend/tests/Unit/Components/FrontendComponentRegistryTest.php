<?php

declare(strict_types=1);

use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Frontend\Support\Components\FrontendComponentRegistry;

it('emits the same receipt for a direct component registry call', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/frontend', 'frontend', 'Vendor\\Frontend\\Provider'),
        function (): void {
            (new FrontendComponentRegistry)->register('vendor.card', 'vendor::card');
        },
    );

    expect($receipts->forPackage('vendor/frontend'))->toHaveCount(1)
        ->and($receipts->forPackage('vendor/frontend')[0]->key)->toBe('vendor.card');
});

it('resolves registered component keys and aliases to their blade component', function (): void {
    $registry = new FrontendComponentRegistry;

    $registry->register(
        key: 'section.block',
        component: 'capell-content::section.block',
        aliases: ['capell-content-sections::section.block'],
        props: ['asset', 'title'],
    );

    expect($registry->resolve('section.block'))->toBe('capell-content::section.block')
        ->and($registry->resolve('capell-content-sections::section.block'))->toBe('capell-content::section.block')
        ->and($registry->resolve('capell-content::section.block'))->toBe('capell-content::section.block')
        ->and($registry->get('section.block')->props)->toBe(['asset', 'title']);
});

it('passes through unknown components for backwards compatibility', function (): void {
    $registry = new FrontendComponentRegistry;

    expect($registry->resolve('app::custom.card'))->toBe('app::custom.card');
});
