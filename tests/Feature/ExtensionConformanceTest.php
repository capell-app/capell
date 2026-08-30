<?php

declare(strict_types=1);

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Data\Extensions\ExtensionContributionReceiptData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Testing\ExtensionTestHarness;
use Filament\Panel;
use Illuminate\Support\Facades\File;
use Vendor\ExtensionConformance\AdminProvider;
use Vendor\ExtensionConformance\AuthProvider;
use Vendor\ExtensionConformance\ConformanceRecorder;
use Vendor\ExtensionConformance\FrontendProvider;
use Vendor\ExtensionConformance\InstallProvider;
use Vendor\ExtensionConformance\MetadataProvider;
use Vendor\ExtensionConformance\RuntimeProvider;

foreach (glob(dirname(__DIR__, 2) . '/packages/core/tests/fixtures/ExtensionConformance/*.php') ?: [] as $fixture) {
    require_once $fixture;
}

it('boots only the provider buckets allowed by the public runtime role', function (): void {
    $directory = makeCoreConformancePackage();
    $recorder = new ConformanceRecorder;
    app()->instance(ConformanceRecorder::class, $recorder);

    try {
        $providers = ExtensionTestHarness::forPath($directory)->bootProviders(app(), RuntimeRole::Public);

        expect($providers)->toBe([
            MetadataProvider::class,
            RuntimeProvider::class,
            FrontendProvider::class,
            AuthProvider::class,
        ])
            ->and($recorder->events())->toBe(['metadata', 'runtime', 'frontend', 'auth'])
            ->and(array_map(
                static fn (ExtensionContributionReceiptData $receipt): array => [$receipt->providerBucket, $receipt->type->value, $receipt->key],
                resolve(ExtensionContributionReceiptRegistry::class)->forPackage('capell-tests/conformance'),
            ))->toBe([
                ['metadata', 'model', 'fixture.metadata'],
                ['runtime', 'model', 'fixture.runtime'],
                ['frontend', 'render-hook', 'fixture.frontend'],
                ['auth', 'permission', 'fixture.auth'],
            ])
            ->and(resolve(ExtensionContributionReceiptRegistry::class)->loadedBuckets('capell-tests/conformance'))
            ->toBe(['metadata', 'runtime', 'frontend', 'auth']);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('boots install and admin buckets in the host-owned combined role', function (): void {
    $directory = makeCoreConformancePackage();
    $recorder = new ConformanceRecorder;
    app()->instance(ConformanceRecorder::class, $recorder);

    try {
        $providers = ExtensionTestHarness::forPath($directory)->bootProviders(app(), RuntimeRole::Combined);

        expect($providers)->toBe([
            MetadataProvider::class,
            InstallProvider::class,
            RuntimeProvider::class,
            AuthProvider::class,
            AdminProvider::class,
            FrontendProvider::class,
        ])
            ->and($recorder->events())->toBe(['metadata', 'install', 'runtime', 'auth', 'admin', 'frontend'])
            ->and(array_map(
                static fn (ExtensionContributionReceiptData $receipt): array => [$receipt->providerBucket, $receipt->type->value, $receipt->key],
                resolve(ExtensionContributionReceiptRegistry::class)->forPackage('capell-tests/conformance'),
            ))->toBe([
                ['metadata', 'model', 'fixture.metadata'],
                ['install', 'install-patch', 'fixture.install'],
                ['runtime', 'model', 'fixture.runtime'],
                ['auth', 'permission', 'fixture.auth'],
                ['admin', 'admin-page', 'fixture.admin'],
                ['frontend', 'render-hook', 'fixture.frontend'],
            ])
            ->and(resolve(ExtensionContributionReceiptRegistry::class)->loadedBuckets('capell-tests/conformance'))
            ->toBe(['metadata', 'install', 'runtime', 'auth', 'admin', 'frontend']);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('boots CapellAdminPlugin in a host-owned Filament panel', function (): void {
    $panel = Panel::make()
        ->id('core-conformance')
        ->path('core-conformance')
        ->plugin(CapellAdminPlugin::make());

    CapellAdminPlugin::make()->register($panel);

    expect($panel->hasPlugin(CapellAdminPlugin::ID))
        ->toBeTrue()
        ->and($panel->getPages())->not->toBeEmpty();
});

function makeCoreConformancePackage(): string
{
    $directory = sys_get_temp_dir() . '/capell-core-conformance-' . bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);
    File::put($directory . '/composer.json', json_encode([
        'name' => 'capell-tests/conformance',
        'autoload' => [
            'psr-4' => [
                'Vendor\\ExtensionConformance\\' => dirname(__DIR__, 2) . '/packages/core/tests/fixtures/ExtensionConformance/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    File::put($directory . '/capell.json', json_encode(
        capellManifestV3Array(
            name: 'capell-tests/conformance',
            surfaces: ['admin', 'frontend', 'shared'],
            namespace: 'Vendor\\ExtensionConformance',
            providers: [
                'metadata' => [MetadataProvider::class],
                'install' => [InstallProvider::class],
                'runtime' => [RuntimeProvider::class],
                'auth' => [AuthProvider::class],
                'admin' => [AdminProvider::class],
                'frontend' => [FrontendProvider::class],
            ],
        ),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));

    return $directory;
}
