<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessUnionReturnDocblockRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\Class_\AddHasFactoryToModelsRector;
use RectorLaravel\Rector\ClassMethod\MakeModelAttributesAndScopesProtectedRector;
use RectorLaravel\Rector\If_\AbortIfRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;
use Sinnbeck\DomAssertions\Rector\Rules\AssertElementToAssertContainsElementRule;

$packagePaths = [];

foreach (['config', 'database', 'publishes', 'resources', 'routes', 'src', 'tests'] as $packageDirectory) {
    foreach (glob(__DIR__ . '/packages/*/' . $packageDirectory, GLOB_ONLYDIR) ?: [] as $path) {
        $packagePaths[] = $path;
    }
}

return RectorConfig::configure()
    ->withSetProviders(LaravelSetProvider::class)
    ->withSets([
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_LEGACY_FACTORIES_TO_CLASSES,
    ])
    ->withImportNames(
        removeUnusedImports: true,
    )
    ->withCache(
        cacheDirectory: '/tmp/rector/capell-4',
        cacheClass: FileCacheStorage::class,
    )
    ->withPaths([
        __DIR__ . '/rector.php',
        ...(glob(__DIR__ . '/packages/*/rector.php') ?: []),
        ...$packagePaths,
        __DIR__ . '/tests',
    ])
    ->withParallel(
        timeoutSeconds: 600,
        maxNumberOfProcess: 8,
        jobSize: 8,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withRules([
        AddOverrideAttributeToOverriddenMethodsRector::class,
        AssertElementToAssertContainsElementRule::class,
    ])
    ->withSkip([
        PostIncDecToPreIncDecRector::class,
        AddTypeToConstRector::class,
        PrivatizeFinalClassPropertyRector::class,
        ReadOnlyClassRector::class,
        RemoveUselessVarTagRector::class => [
            __DIR__ . '/packages/admin/src/Settings/AdminSettings.php',
            __DIR__ . '/packages/core/src/ThemeStudio/Settings/ThemeStudioSettings.php',
        ],
        RemoveUselessUnionReturnDocblockRector::class => [
            __DIR__ . '/packages/admin/src/Filament/Pages/UpgradePage.php',
        ],
        RemoveUselessReturnTagRector::class => [
            __DIR__ . '/packages/core/src/Support/Models/ModelInterceptorRegistry.php',
        ],
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/packages/core/src/Actions/Extensions/BuildExtensionSurfaceCatalogAction.php',
        ],
        NarrowObjectReturnTypeRector::class => [
            __DIR__ . '/packages/core/src/Support/Models/ModelInterceptorRegistry.php',
        ],
        AbortIfRector::class => [
            __DIR__ . '/packages/frontend/src/Livewire/Page/Sitemap.php',
        ],
        MakeModelAttributesAndScopesProtectedRector::class => [
            __DIR__ . '/packages/core/src/Enums/Attribute/EnumAttributeHelper.php',
        ],
        AddHasFactoryToModelsRector::class => [
            __DIR__ . '/packages/admin/tests/Fixtures/Models',
        ],
        // Fixture properties here are read via reflection by SingletonLifetimeGuard,
        // which Rector cannot see, so it treats them as unused and deletes them.
        RemoveUnusedPrivatePropertyRector::class => [
            __DIR__ . '/packages/core/tests/Unit/Octane/SingletonLifetimeInventoryTest.php',
        ],
        EnvVariableToEnvHelperRector::class => [
            __DIR__ . '/packages/core/tests/Integration/Actions/RemovePackageActionComposerConsumerTest.php',
        ],
        ServerVariableToRequestFacadeRector::class => [
            __DIR__ . '/packages/core/tests/Integration/Actions/RemovePackageActionComposerConsumerTest.php',
        ],
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__ . '/packages/admin/tests/Feature/Filament/Pages/ExtensionsPageTest.php',
            __DIR__ . '/packages/admin/tests/Feature/Filament/Resources/Theme/Pages/ManageThemesTest.php',
            __DIR__ . '/packages/marketplace/tests/Feature/Filament/MarketplacePackageOperationsPageTest.php',
        ],
        NullToStrictStringFuncCallArgRector::class => [
            __DIR__ . '/packages/core/src/Console/Commands/PublishComponentsCommand.php',
            __DIR__ . '/packages/core/src/Testing/ExtensionTestHarness.php',
            __DIR__ . '/packages/core/src/Support/Sitemap/XmlSitemapGenerator.php',
            __DIR__ . '/packages/frontend/src/Livewire/Page/Sitemap.php',
        ],
        __DIR__ . '/tests/.pest',
    ])
    ->withPhpSets();
