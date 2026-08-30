<?php

declare(strict_types=1);

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

it('keeps registries on the shared keyed base or an explicit distinct-shape allowlist', function (): void {
    $repositoryRoot = dirname(__DIR__, 4);
    $distinctRegistries = [
        'packages/admin/src/Support/Activity/ActivityResourceLinkRegistry.php',
        'packages/admin/src/Support/AdminEventRegistry.php',
        'packages/admin/src/Support/AdminSurfaceContributionRegistry.php',
        // Partitioned, ordered contribution storage with enum-owned vocabularies and freeze/replace semantics.
        'packages/admin/src/Support/AdminZoneRegistry.php',
        // Resolves tagged enum presentation contributors; it has no generic keyed item lifecycle.
        'packages/admin/src/Support/Enums/EnumPresentationRegistry.php',
        'packages/admin/src/Support/AdminTools/AdminToolRegistry.php',
        'packages/admin/src/Support/Bridges/AdminBridgeRegistry.php',
        'packages/admin/src/Support/Extensions/ExtensionManagementSurfaceRegistry.php',
        'packages/admin/src/Support/Extensions/ExtensionPageRegistry.php',
        'packages/admin/src/Support/Extensions/ExtensionsPageActionRegistry.php',
        'packages/admin/src/Support/Themes/ThemeEditorExtensionRegistry.php',
        // Workspace definitions are security-filtered, generation-tracked discovery state rather than keyed values.
        'packages/admin/src/Support/Workspace/AdminWorkspaceRegistry.php',
        'packages/core/src/EventSourcing/Rollback/RollbackValidatorRegistry.php',
        'packages/core/src/Support/Components/ComponentRegistry.php',
        'packages/core/src/Support/ContentGraph/ContentGraphRegistry.php',
        // Blueprint descriptors own validation and package-install reopen semantics.
        'packages/core/src/Support/BlueprintSubjectRegistry.php',
        // One platform can own multiple driver aliases and resolves connection/server families.
        'packages/core/src/Support/Database/DatabasePlatformRegistry.php',
        // Records provider/namespace contribution receipts and context, not keyed extension items.
        'packages/core/src/Support/Extensions/ExtensionContributionReceiptRegistry.php',
        // Discovers tagged checks and validates category/timeout metadata.
        'packages/core/src/Support/Health/HealthCheckRegistry.php',
        'packages/core/src/Support/Install/InstallPatchRegistry.php',
        'packages/core/src/Support/Models/ModelInterceptorRegistry.php',
        // Lifecycle-aware manifest event definitions with install reopen/freeze and domain validation.
        'packages/core/src/Support/OutboundEventRegistry.php',
        // Aggregates publication readiness contributors and owns the readiness state machine.
        'packages/core/src/Support/Publishing/PublicationReadinessRegistry.php',
        // Keyed by an integer schema version and walked as an ordered migration chain, not looked up by a single string key.
        'packages/core/src/Support/ProjectBuild/ProjectBuildManifestMigrationRegistry.php',
        'packages/core/src/Support/Registries/AbstractKeyedRegistry.php',
        'packages/core/src/Support/Registries/TaggedProviderRegistry.php',
        'packages/core/src/Support/Renderables/RenderableRegistry.php',
        'packages/core/src/Support/Settings/SettingsSchemaRegistry.php',
        'packages/core/src/Support/Subscriber/SubscriberRegistry.php',
        'packages/core/src/Support/Tailwind/TailwindAssetsRegistry.php',
        'packages/core/src/Support/Themes/ThemeChromeRegistry.php',
        'packages/core/src/Support/Themes/ThemeInstallDefaultsRegistry.php',
        'packages/core/src/ThemeStudio/Theme/PagePresentationRegistry.php',
        'packages/core/src/ThemeStudio/Theme/WidgetPresentationRegistry.php',
        'packages/frontend/src/Support/Assets/FrontendPackageDependencyRegistry.php',
        'packages/frontend/src/Support/Cache/CacheInvalidationDependencyRegistry.php',
        'packages/frontend/src/Support/Cache/CacheInvalidationRegistry.php',
        'packages/frontend/src/Support/Cache/TranslationCacheDependencyRegistry.php',
        // Persists and locks model cache destinations; it is not an in-memory keyed contribution map.
        'packages/frontend/src/Support/Cache/PublicRenderDataCacheDependencyRegistry.php',
        'packages/frontend/src/Support/Render/RenderHookRegistry.php',
        // Request-context-aware public transport preparation around keyed contributors.
        'packages/frontend/src/Support/Render/PublicRenderDataContributorRegistry.php',
        'packages/frontend/src/Support/Renderables/RenderableDynamicDataRegistry.php',
        'packages/frontend/src/Support/Routing/FrontendRouteMiddlewareRegistry.php',
        'packages/frontend/src/Support/Routing/ReservedFrontendPathRegistry.php',
        // Holds an injected iterable of contributors with no keyed storage at all; it answers a boolean, it does not look anything up by key.
        'packages/frontend/src/Support/SiteAccess/SiteAccessExemptionRegistry.php',
        'packages/marketplace/src/Support/MarketplaceComposerChangePublisherRegistry.php',
    ];
    $nonCanonical = [];

    foreach (providerPatternPhpPaths($repositoryRoot, static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Registry.php')) as $path) {
        $relativePath = str_replace($repositoryRoot . '/', '', $path);

        if (! providerPatternExtendsAbstractKeyedRegistry($path) && ! in_array($relativePath, $distinctRegistries, true)) {
            $nonCanonical[] = $relativePath;
        }
    }

    expect($nonCanonical)->toBe([]);
});

it('keeps filesystem work out of service providers except documented bootstrap probes', function (): void {
    $repositoryRoot = dirname(__DIR__, 4);
    $allowedCalls = [
        // Falls back to walking parent directories for composer.json when Composer's InstalledVersions has no
        // install path for the package (for example a path-repo or source-only embed), to locate the package root.
        'packages/core/src/Support/Packages/AbstractPackageServiceProvider.php:is_file',
        // The frontend package may be installed without its published view directory.
        'packages/frontend/src/Providers/FrontendServiceProvider.php:is_dir',
    ];
    $calls = [];

    foreach (providerPatternPhpPaths($repositoryRoot, static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'ServiceProvider.php')) as $path) {
        foreach (providerPatternFunctionCalls($path) as $call) {
            if (in_array($call, ['file_get_contents', 'file_put_contents', 'glob', 'is_dir', 'is_file', 'scandir'], true)) {
                $calls[] = str_replace($repositoryRoot . '/', '', $path) . ':' . $call;
            }
        }

        foreach (providerPatternStaticCalls($path) as $call) {
            if ($call['class'] === File::class) {
                $calls[] = sprintf(
                    '%s:File::%s',
                    str_replace($repositoryRoot . '/', '', $path),
                    $call['method'],
                );
            }
        }
    }

    sort($calls);

    expect($calls)->toBe($allowedCalls);
});

it('allows static availability probes only for genuine optional integrations', function (): void {
    $repositoryRoot = dirname(__DIR__, 4);
    $allowedProbes = [
        // Composer's runtime API is absent when marketplace diagnostics run from a source-only embed.
        'packages/marketplace/src/Actions/RunMarketplaceInstallPreflightChecksAction.php:class_exists:Composer\\InstalledVersions',
        'packages/marketplace/src/Actions/StartMarketplaceAccountConnectionAction.php:class_exists:Composer\\InstalledVersions',
        // Installer's admin bridge remains usable when the separately installable admin package is absent.
        'packages/installer/src/Providers/InstallerAdminServiceProvider.php:class_exists:Capell\\Admin\\Facades\\CapellAdmin',
        // Octane is supported when present but is deliberately not a core dependency.
        'packages/core/src/Providers/CapellServiceProvider.php:interface_exists:Laravel\\Octane\\Contracts\\OperationTerminated',
        // Aggressive prefetching is enabled only on Laravel versions that provide the Vite facade.
        'packages/frontend/src/Providers/FrontendServiceProvider.php:class_exists:Illuminate\\Support\\Facades\\Vite',
    ];
    $probes = [];

    foreach (providerPatternPhpPaths($repositoryRoot) as $path) {
        foreach (providerPatternStaticAvailabilityProbes($path) as $probe) {
            if (str_starts_with($probe['target'], 'Capell\\')
                || str_starts_with($probe['target'], 'Illuminate\\')
                || str_starts_with($probe['target'], 'Laravel\\Octane\\')
                || $probe['target'] === InstalledVersions::class) {
                $probes[] = sprintf(
                    '%s:%s:%s',
                    str_replace($repositoryRoot . '/', '', $path),
                    $probe['function'],
                    $probe['target'],
                );
            }
        }
    }

    sort($probes);
    sort($allowedProbes);

    expect($probes)->toBe($allowedProbes);
});

/**
 * @param  (callable(SplFileInfo): bool)|null  $filter
 * @return list<string>
 */
function providerPatternPhpPaths(string $repositoryRoot, ?callable $filter = null): array
{
    $paths = [];
    $packages = new DirectoryIterator($repositoryRoot . '/packages');

    foreach ($packages as $package) {
        $sourceRoot = $package->getPathname() . '/src';
        if ($package->isDot()) {
            continue;
        }

        if (! is_dir($sourceRoot)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ($filter === null || $filter($file))) {
                $paths[] = $file->getPathname();
            }
        }
    }

    sort($paths);

    return $paths;
}

function providerPatternExtendsAbstractKeyedRegistry(string $path): bool
{
    return array_any(providerPatternNodes($path), fn (Node $node): bool => $node instanceof Class_ && $node->extends?->toString() === AbstractKeyedRegistry::class);
}

/** @return list<string> */
function providerPatternFunctionCalls(string $path): array
{
    $calls = [];

    foreach (providerPatternNodes($path) as $node) {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $calls[] = $node->name->toString();
        }
    }

    return $calls;
}

/** @return list<array{class: string, method: string}> */
function providerPatternStaticCalls(string $path): array
{
    $calls = [];

    foreach (providerPatternNodes($path) as $node) {
        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && $node->name instanceof Identifier) {
            $calls[] = [
                'class' => $node->class->toString(),
                'method' => $node->name->toString(),
            ];
        }
    }

    return $calls;
}

/** @return list<array{function: string, target: string}> */
function providerPatternStaticAvailabilityProbes(string $path): array
{
    $probes = [];

    foreach (providerPatternNodes($path) as $node) {
        if (! $node instanceof FuncCall) {
            continue;
        }

        if (! $node->name instanceof Name) {
            continue;
        }

        if (! in_array($node->name->toString(), ['class_exists', 'interface_exists', 'method_exists'], true)) {
            continue;
        }

        $target = $node->args[0]->value ?? null;

        if ($target instanceof ClassConstFetch && $target->class instanceof Name) {
            $probes[] = ['function' => $node->name->toString(), 'target' => $target->class->toString()];
        }

        if ($target instanceof String_) {
            $probes[] = ['function' => $node->name->toString(), 'target' => ltrim($target->value, '\\')];
        }
    }

    return $probes;
}

/** @return list<Node> */
function providerPatternNodes(string $path): array
{
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($path)) ?? [];
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);

    return array_values((new NodeFinder)->findInstanceOf($traverser->traverse($statements), Node::class));
}
