<?php

declare(strict_types=1);

use Capell\Core\Concerns\HasModelRelations;
use Capell\Core\Octane\Resettable;
use Capell\Core\Octane\SingletonLifetime;
use Capell\Core\Octane\SingletonLifetimeInventory;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Capell\Frontend\Contracts\AssetsRegistryInterface;
use Capell\Frontend\Support\Assets\FrontendAssetsService;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Capell\Frontend\Support\Security\FrontendUrlSignatureService;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Capell\Marketplace\Actions\PhoneHomeAction;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class SingletonLifetimeParentFixture
{
    protected array $parentCache = [];
}

final class SingletonLifetimeFixture extends SingletonLifetimeParentFixture
{
    private string $operation = '';

    private readonly string $immutable;

    public function __construct()
    {
        $this->immutable = 'fixed';
    }
}

/** @return array<string, array{file: string, abstract: string}> */
function capellSingletonTargets(): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $targets = [];
    $files = glob(dirname(__DIR__, 4) . '/*/src/{Providers,Support/Bootstrap}/*.php', GLOB_BRACE);

    foreach ($files ?: [] as $file) {
        $nodes = $parser->parse((string) file_get_contents($file)) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $nodes = $traverser->traverse($nodes);

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), ['singleton', 'singletonIf'], true));

        foreach ($calls as $call) {
            $abstract = capellClassNameFromNode($call->args[0]->value ?? null);
            $target = capellClassNameFromNode($call->args[1]->value ?? null) ?? $abstract;

            if ($target === null || ! str_starts_with($target, 'Capell\\')) {
                continue;
            }

            $targets[$target] = ['file' => $file, 'abstract' => $abstract ?? $target];
        }
    }

    ksort($targets);

    return $targets;
}

function capellClassNameFromNode(?Node $node): ?string
{
    if ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier && $node->name->toString() === 'class') {
        return $node->class instanceof Node\Name ? $node->class->toString() : null;
    }

    if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
        $new = (new NodeFinder)->findFirstInstanceOf($node, Node\Expr\New_::class);

        return $new instanceof Node\Expr\New_ && $new->class instanceof Node\Name ? $new->class->toString() : null;
    }

    return null;
}

/** @return array<string, array{file: string, abstract: string}> */
function capellScopedTargets(): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $targets = [];

    foreach (glob(dirname(__DIR__, 4) . '/*/src/{Providers,Support/Bootstrap}/*.php', GLOB_BRACE) ?: [] as $file) {
        $nodes = $parser->parse((string) file_get_contents($file)) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $nodes = $traverser->traverse($nodes);
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier && $node->name->toString() === 'scoped');

        foreach ($calls as $call) {
            $abstract = capellClassNameFromNode($call->args[0]->value ?? null);
            $target = capellClassNameFromNode($call->args[1]->value ?? null) ?? $abstract;

            if ($target !== null && str_starts_with($target, 'Capell\\')) {
                $targets[$target] = ['file' => $file, 'abstract' => $abstract ?? $target];
            }
        }
    }

    return $targets;
}

/** @return array<string, true> */
function capellResettableTaggedTargets(): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $targets = [];

    foreach (glob(dirname(__DIR__, 4) . '/*/src/{Providers,Support/Bootstrap}/*.php', GLOB_BRACE) ?: [] as $file) {
        $nodes = $parser->parse((string) file_get_contents($file)) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $nodes = $traverser->traverse($nodes);
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier && $node->name->toString() === 'tag');

        foreach ($calls as $call) {
            $tag = $call->args[1]->value ?? null;

            if (! $tag instanceof Node\Expr\ClassConstFetch
                || ! $tag->class instanceof Node\Name
                || $tag->class->toString() !== Resettable::class
                || ! $call->args[0]->value instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($call->args[0]->value->items as $item) {
                $target = capellClassNameFromNode($item?->value);

                if ($target !== null) {
                    $targets[$target] = true;
                }
            }
        }
    }

    return $targets;
}

/** @return array<string, string> */
function capellMutatedStaticState(): array
{
    $hazards = [];
    $packages = new DirectoryIterator(dirname(__DIR__, 4));

    foreach ($packages as $package) {
        if (! $package->isDir() || $package->isDot() || ! is_dir($package->getPathname() . '/src')) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package->getPathname() . '/src'));

        foreach ($files as $candidate) {
            if (! $candidate->isFile() || $candidate->getExtension() !== 'php') {
                continue;
            }

            $file = $candidate->getPathname();
            $source = (string) file_get_contents($file);

            if (! preg_match('/namespace\s+([^;]+);/', $source, $namespace)
                || ! preg_match('/\b(?:class|trait)\s+(\w+)/', $source, $class)) {
                continue;
            }

            preg_match_all('/(?:private|protected|public)\s+static\s+[^\n;]*\$(\w+)(?:\s*=[^;]*)?;/', $source, $properties);
            $withoutDeclarations = preg_replace('/(?:private|protected|public)\s+static\s+[^\n;]+;/', '', $source) ?? $source;

            foreach ($properties[1] as $property) {
                $quoted = preg_quote($property, '/');

                if (preg_match('/self::\$' . $quoted . '\s*\?\?=|self::\$' . $quoted . '\s*=|self::\$' . $quoted . '\[[^;]+\]\s*=|self::\$' . $quoted . '\[\]\s*=/', $withoutDeclarations)) {
                    $hazards[trim($namespace[1]) . '\\' . $class[1]] = $file;
                }
            }
        }
    }

    ksort($hazards);

    return $hazards;
}

/** @return list<string> */
function capellMutableInstanceProperties(string $class): array
{
    if (! class_exists($class)) {
        return [];
    }

    return array_values(collect((new ReflectionClass($class))->getProperties())
        ->reject(static fn (ReflectionProperty $property): bool => $property->isStatic() || $property->isReadOnly())
        ->map(static fn (ReflectionProperty $property): string => $property->getDeclaringClass()->getName() . '::$' . $property->getName())
        ->values()
        ->all());
}

it('classifies every mutable Capell production singleton with an exact concrete target', function (): void {
    $bindings = capellSingletonTargets();
    $inventory = SingletonLifetimeInventory::mutableSingletons();
    $mutableBindings = collect($bindings)
        ->filter(static fn (array $binding, string $target): bool => capellMutableInstanceProperties($target) !== [])
        ->all();

    $missing = array_diff_key($mutableBindings, $inventory);
    $stale = array_diff_key($inventory, $bindings);

    expect($missing)->toBe([], 'Unclassified mutable singleton targets: ' . json_encode($missing, JSON_PRETTY_PRINT))
        ->and($stale)->toBe([], 'Classifications without a production singleton binding: ' . json_encode(array_keys($stale), JSON_PRETTY_PRINT));
});

it('enforces request mutable singleton reset protection without scoped dual registration', function (): void {
    $scopedTargets = capellScopedTargets();
    $taggedTargets = capellResettableTaggedTargets();

    expect(array_intersect_key($scopedTargets, $taggedTargets))
        ->toBe([], 'A service cannot be both scoped and tagged for reset');

    foreach (SingletonLifetimeInventory::mutableSingletons() as $class => $classification) {
        expect(class_exists($class))->toBeTrue("Classified singleton [{$class}] must exist")
            ->and($classification['reason'])->not->toBeEmpty();

        if ($classification['lifetime'] !== SingletonLifetime::RequestMutable) {
            continue;
        }

        expect($scopedTargets)->not->toHaveKey($class, "Request-mutable singleton [{$class}] must not also be scoped");

        if ($classification['protection'] === 'tagged') {
            expect(is_a($class, Resettable::class, true))->toBeTrue("Tagged singleton [{$class}] must implement Resettable");
            expect($taggedTargets)->toHaveKey($class, "Resettable singleton [{$class}] must be tagged in its provider");
        }

        if ($classification['protection'] === 'delegated') {
            expect(is_a(CapellCoreManager::class, Resettable::class, true))
                ->toBeTrue("Delegated singleton [{$class}] requires the tagged core manager flush");
        }
    }
});

it('resolves interface and closure binding targets explicitly', function (): void {
    expect(capellSingletonTargets())
        ->toHaveKey(FrontendAssetsService::class)
        ->and(capellSingletonTargets()[FrontendAssetsService::class]['abstract'])
        ->toBe(AssetsRegistryInterface::class)
        ->and(capellSingletonTargets())
        ->toHaveKey(FrontendUrlSignatureService::class)
        ->and(capellScopedTargets()[ThemeStudioSettings::class]['abstract'])
        ->toBe(ThemeRuntimeSettings::class);
});

it('keeps known process static hazards explicit and request-safe', function (): void {
    $knownClean = [
        PhoneHomeAction::class,
        InstallerPreflight::class,
        ErrorPageFallbackManifestStore::class,
        FrontendLogger::class,
    ];

    foreach ($knownClean as $class) {
        $mutableStatic = collect((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC))
            ->reject(static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->isEnum())
            ->all();

        expect($mutableStatic)->toBe([], "Known operation service [{$class}] must not retain static state");
    }

    expect(array_keys(capellMutatedStaticState()))
        ->toEqualCanonicalizing(array_keys(SingletonLifetimeInventory::mutableStaticState()))
        ->and(SingletonLifetimeInventory::mutableStaticState())
        ->toHaveKey(HasModelRelations::class);
});

it('characterizes mutable property detection through parent and trait state', function (): void {
    expect(capellMutableInstanceProperties(SingletonLifetimeFixture::class))
        ->toContain(SingletonLifetimeFixture::class . '::$operation')
        ->toContain(SingletonLifetimeParentFixture::class . '::$parentCache')
        ->not->toContain(SingletonLifetimeFixture::class . '::$immutable');
});
