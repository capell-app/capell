<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
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
        'packages/admin/src/Support/AdminTools/AdminToolRegistry.php',
        'packages/admin/src/Support/Bridges/AdminBridgeRegistry.php',
        'packages/admin/src/Support/Extensions/ExtensionManagementSurfaceRegistry.php',
        'packages/admin/src/Support/Extensions/ExtensionPageRegistry.php',
        'packages/admin/src/Support/Extensions/ExtensionsPageActionRegistry.php',
        'packages/admin/src/Support/Themes/ThemeEditorExtensionRegistry.php',
        'packages/core/src/EventSourcing/Rollback/RollbackValidatorRegistry.php',
        'packages/core/src/Support/Components/ComponentRegistry.php',
        'packages/core/src/Support/ContentGraph/ContentGraphRegistry.php',
        'packages/core/src/Support/Install/InstallPatchRegistry.php',
        'packages/core/src/Support/Models/ModelInterceptorRegistry.php',
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
        'packages/frontend/src/Support/Render/RenderHookRegistry.php',
        'packages/frontend/src/Support/Renderables/RenderableDynamicDataRegistry.php',
        'packages/frontend/src/Support/Routing/FrontendRouteMiddlewareRegistry.php',
        'packages/frontend/src/Support/Routing/ReservedFrontendPathRegistry.php',
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
            if ($call['class'] === 'Illuminate\\Support\\Facades\\File') {
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
                || $probe['target'] === 'Composer\\InstalledVersions') {
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

        if ($package->isDot() || ! is_dir($sourceRoot)) {
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
    foreach (providerPatternNodes($path) as $node) {
        if ($node instanceof Node\Stmt\Class_ && $node->extends?->toString() === 'Capell\\Core\\Support\\Registries\\AbstractKeyedRegistry') {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function providerPatternFunctionCalls(string $path): array
{
    $calls = [];

    foreach (providerPatternNodes($path) as $node) {
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
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
        if ($node instanceof Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier) {
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
        if (! $node instanceof Expr\FuncCall
            || ! $node->name instanceof Node\Name
            || ! in_array($node->name->toString(), ['class_exists', 'interface_exists', 'method_exists'], true)) {
            continue;
        }

        $target = $node->args[0]->value ?? null;

        if ($target instanceof Expr\ClassConstFetch && $target->class instanceof Node\Name) {
            $probes[] = ['function' => $node->name->toString(), 'target' => $target->class->toString()];
        }

        if ($target instanceof Node\Scalar\String_) {
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
