<?php

declare(strict_types=1);

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Exceptions\BlueprintSubjectRegistrationException;
use Capell\Core\Exceptions\OutboundEventRegistrationException;
use Capell\Core\Models\Page;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Core\Support\OutboundEventRegistry;
use Capell\Core\Testing\ExtensionTestHarness;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Facades\File;
use Vendor\ExtensionConformance\ConformanceOutboundEvent;
use Vendor\ExtensionConformance\ConformanceRenderHook;
use Vendor\ExtensionConformance\MissingReceiptProvider;
use Vendor\ExtensionConformance\WrongContextProvider;

foreach (glob(dirname(__DIR__, 2) . '/packages/core/tests/fixtures/ExtensionConformance/*.php') ?: [] as $fixture) {
    require_once $fixture;
}

it('catches a loaded provider whose declared contribution emitted no receipt', function (): void {
    $directory = makeFailureConformancePackage(
        name: 'capell-tests/conformance-failure',
        providers: ['runtime' => [MissingReceiptProvider::class]],
        contributes: [[
            'type' => 'render-hook',
            'class' => ConformanceRenderHook::class,
            'key' => 'fixture.missing-receipt',
            'providerBucket' => 'runtime',
        ]],
    );

    try {
        $harness = ExtensionTestHarness::forPath($directory);
        $harness->bootProviders(app(), RuntimeRole::Public);
        expect(array_column($harness->auditResults(), 'message'))
            ->toContain('Declared contribution is not registered at runtime.');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('catches a receipt emitted in the wrong provider context', function (): void {
    $directory = makeFailureConformancePackage(
        name: 'capell-tests/conformance-failure',
        providers: ['runtime' => [WrongContextProvider::class]],
        contributes: [[
            'type' => 'outbound-event',
            'class' => ConformanceOutboundEvent::class,
            'key' => 'fixture.wrong-context',
            'providerBucket' => 'runtime',
        ]],
    );

    try {
        $harness = ExtensionTestHarness::forPath($directory);
        $harness->bootProviders(app(), RuntimeRole::Public);
        expect(array_column($harness->auditResults(), 'message'))
            ->toContain('Runtime contribution is registered in the wrong provider bucket.');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects duplicate outbound-event keys', function (): void {
    $registry = new OutboundEventRegistry;
    $definition = new OutboundEventDefinitionData(
        name: 'capell-tests.duplicate',
        version: 1,
        payloadClass: OutboundEventDefinitionData::class,
        description: 'Duplicate conformance fixture.',
        ownerPackage: 'capell-tests/conformance-failure',
    );

    $registry->register($definition);

    expect(fn (): OutboundEventRegistry => $registry->register($definition))
        ->toThrow(OutboundEventRegistrationException::class, 'already registered');
});

it('rejects late blueprint-subject registration after the boot freeze', function (): void {
    $registry = new BlueprintSubjectRegistry;
    $registry->freeze();

    expect(fn (): BlueprintSubjectRegistry => $registry->register(new BlueprintSubjectDescriptorData(
        key: 'capell-tests.late-subject',
        label: 'Late subject',
        modelClass: Page::class,
        ownerPackage: 'capell-tests/conformance-failure',
    )))->toThrow(BlueprintSubjectRegistrationException::class, 'cannot be registered after boot');
});

it('keeps relative render-hook ordering deterministic when registration order differs', function (): void {
    $registry = new RenderHookRegistry;

    $registry->registerInlineBlade(RenderHookLocation::Footer, '<span>later</span>', priority: 20);
    $registry->registerInlineBlade(RenderHookLocation::Footer, '<span>earlier</span>', priority: 10);

    expect($registry->renderAll(RenderHookLocation::Footer))
        ->toBe('<span>earlier</span><span>later</span>');
});

it('rejects unsafe public cache variance in a frontend manifest', function (): void {
    $directory = makeFailureConformancePackage(
        name: 'capell-tests/cache-failure',
        contributes: [[
            'type' => 'render-hook',
            'class' => ConformanceRenderHook::class,
            'key' => 'fixture.cached-hook',
            'providerBucket' => 'frontend',
        ]],
        overrides: [
            'performance' => [
                'cacheSafety' => [
                    'cacheable' => true,
                    'variesBy' => ['user'],
                    'cacheTags' => ['fixture-cache'],
                    'invalidationSources' => [['model' => Page::class, 'events' => ['saved']]],
                ],
            ],
        ],
    );

    try {
        expect(fn (): ExtensionTestHarness => ExtensionTestHarness::forPath($directory)->assertNoUnsafePublicCache())
            ->toThrow(AssertionError::class, 'unsafe public cache variance');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects root-relative asset URLs in a public theme fixture', function (): void {
    $directory = makeFailureConformanceTheme();

    try {
        expect(fn (): ExtensionTestHarness => ExtensionTestHarness::forPath($directory)->assertThemeUsesSafeAssetUrls())
            ->toThrow(AssertionError::class, 'root-relative asset URLs');
    } finally {
        File::deleteDirectory($directory);
    }
});

/**
 * @param  array<string, list<class-string>>  $providers
 * @param  list<array<string, mixed>>  $contributes
 * @param  array<string, mixed>  $overrides
 */
function makeFailureConformancePackage(
    string $name,
    array $providers = [],
    array $contributes = [],
    array $overrides = [],
): string {
    $directory = sys_get_temp_dir() . '/capell-core-conformance-failure-' . bin2hex(random_bytes(6));
    $namespace = 'Vendor\\ExtensionConformance';

    File::ensureDirectoryExists($directory);
    File::put($directory . '/composer.json', json_encode([
        'name' => $name,
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => dirname(__DIR__, 2) . '/packages/core/tests/fixtures/ExtensionConformance/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    File::put($directory . '/capell.json', json_encode(
        capellManifestV3Array(
            name: $name,
            surfaces: ['admin', 'frontend', 'shared'],
            namespace: $namespace,
            providers: $providers,
            overrides: array_replace_recursive($overrides, ['contributes' => $contributes]),
        ),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));

    return $directory;
}

function makeFailureConformanceTheme(): string
{
    $directory = makeFailureConformancePackage(
        name: 'capell-tests/theme-failure',
        overrides: [
            'kind' => 'theme',
            'themeKey' => 'failure-theme',
        ],
    );

    File::ensureDirectoryExists($directory . '/resources/views');
    File::put($directory . '/resources/views/unsafe.blade.php', '<img src="/images/unsafe.png">');

    return $directory;
}
