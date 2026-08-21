<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Core\Data\Manifest\ExtensionProviderData;
use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Capell\Tests\Fixtures\RuntimeRole\Filament\AuthoringRuntimeRoleProvider;
use Capell\Tests\Fixtures\RuntimeRole\FrontendPreviewRuntimeRoleProvider;

it('accepts only immutable named runtime roles and falls back safely for invalid configuration', function (): void {
    expect(RuntimeRoleSelectionData::fromConfiguredValue(' PUBLIC '))
        ->role->toBe(RuntimeRole::Public)
        ->valid->toBeTrue()
        ->and(RuntimeRoleSelectionData::fromConfiguredValue('mutable'))
        ->role->toBe(RuntimeRole::Combined)
        ->valid->toBeFalse();
});

it('removes authoring packages and providers from the public Laravel graph', function (): void {
    $policy = new RuntimeRoleProviderPolicy;

    expect($policy->filterLaravelPackage('capell-app/admin', [
        'providers' => [AuthoringRuntimeRoleProvider::class],
    ], RuntimeRole::Public))->toBeNull()
        ->and($policy->filterLaravelPackage('vendor/public-package', [
            'providers' => [
                FrontendPreviewRuntimeRoleProvider::class,
                AuthoringRuntimeRoleProvider::class,
            ],
        ], RuntimeRole::Public))->toBe([
            'providers' => [FrontendPreviewRuntimeRoleProvider::class],
        ]);
});

it('removes authoring aliases without discarding safe package discovery', function (): void {
    $policy = new RuntimeRoleProviderPolicy;

    expect($policy->filterLaravelPackage('vendor/mixed-alias-package', [
        'providers' => [],
        'aliases' => [
            'PreviewRuntime' => FrontendPreviewRuntimeRoleProvider::class,
            'AuthoringRuntime' => AuthoringRuntimeRoleProvider::class,
        ],
    ], RuntimeRole::Public))->toBe([
        'providers' => [],
        'aliases' => [
            'PreviewRuntime' => FrontendPreviewRuntimeRoleProvider::class,
        ],
    ])->and($policy->filterLaravelPackage('vendor/authoring-alias-package', [
        'providers' => [],
        'aliases' => [
            'AuthoringRuntime' => AuthoringRuntimeRoleProvider::class,
        ],
    ], RuntimeRole::Public))->toBeNull();
});

it('loads frontend and auth capabilities publicly while retaining every bucket for authoring previews', function (): void {
    $policy = new RuntimeRoleProviderPolicy;
    $providers = new ExtensionProviderData(
        metadata: ['MetadataProvider'],
        install: ['InstallProvider'],
        runtime: ['RuntimeProvider'],
        auth: ['AuthProvider'],
        admin: ['AdminProvider'],
        frontend: ['FrontendProvider'],
    );

    expect($policy->extensionProviders($providers, RuntimeRole::Public))->toBe([
        'MetadataProvider',
        'RuntimeProvider',
        'FrontendProvider',
        'AuthProvider',
    ])->and($policy->extensionProviders($providers, RuntimeRole::Authoring))->toBe($providers->all());
});

it('reduces the first-party public bootstrap graph to Core and Frontend', function (): void {
    $providers = [
        CapellServiceProvider::class,
        AdminServiceProvider::class,
        FrontendServiceProvider::class,
        InstallerServiceProvider::class,
        MarketplaceServiceProvider::class,
        AdminPanelProvider::class,
    ];
    $policy = new RuntimeRoleProviderPolicy;

    expect($policy->filterProviders($providers, RuntimeRole::Public))->toBe([
        CapellServiceProvider::class,
        FrontendServiceProvider::class,
    ])->and($policy->filterProviders($providers, RuntimeRole::Combined))->toBe($providers)
        ->and($policy->filterProviders($providers, RuntimeRole::Authoring))->toBe($providers)
        ->and($policy->isFrontendProvider(FrontendServiceProvider::class))->toBeTrue()
        ->and($policy->isFrontendProvider(AdminServiceProvider::class))->toBeFalse();
});
