<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;

it('service providers are loaded', function (): void {
    $providers = app()->getLoadedProviders();
    expect($providers)
        ->toHaveKey(CapellServiceProvider::class)
        ->toHaveKey(AdminServiceProvider::class)
        ->toHaveKey(FrontendServiceProvider::class)
        ->toHaveKey(InstallerServiceProvider::class)
        ->toHaveKey(MarketplaceServiceProvider::class);
});

test('root package replaces split Capell packages as the version-aligned aggregate', function (): void {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($composer, 'replace', []))
        ->toHaveKey('capell-app/core')
        ->toHaveKey('capell-app/admin')
        ->toHaveKey('capell-app/frontend')
        ->toHaveKey('capell-app/installer')
        ->toHaveKey('capell-app/marketplace')
        ->and(data_get($composer, 'extra.laravel.providers'))->toEqualCanonicalizing([
            CapellServiceProvider::class,
            AdminServiceProvider::class,
            FrontendServiceProvider::class,
            InstallerServiceProvider::class,
            MarketplaceServiceProvider::class,
        ]);
});
