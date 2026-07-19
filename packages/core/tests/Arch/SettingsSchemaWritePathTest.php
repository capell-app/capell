<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

it('keeps settings schema registry writes behind the canonical registrars', function (): void {
    $repositoryRoot = dirname(__DIR__, 4);
    $allowedWriters = [
        'packages/admin/src/Support/Bridges/AdminBridgeRegistrar.php',
        'packages/core/src/Support/Packages/PackageSurfaceRegistrar.php',
    ];
    $writers = [];

    foreach (settingsSchemaProductionPhpPaths($repositoryRoot) as $path) {
        if (settingsSchemaSourceWritesToRegistry((string) file_get_contents($path))) {
            $writers[] = str_replace($repositoryRoot . '/', '', $path);
        }
    }

    sort($writers);

    expect($writers)->toBe($allowedWriters);
});

it('recognises every supported direct settings registry write idiom', function (string $source): void {
    expect(settingsSchemaSourceWritesToRegistry("<?php\n" . $source))->toBeTrue();
})->with([
    'resolved local' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry;
        $registry = resolve(SettingsSchemaRegistry::class);
        $registry->register('group', Schema::class);
        PHP,
    'resolved direct chain' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry;
        resolve(SettingsSchemaRegistry::class)->register('group', Schema::class);
        PHP,
    'app direct chain' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry;
        app(SettingsSchemaRegistry::class)->registerMetadata($metadata);
        PHP,
    'application make direct chain' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry;
        $this->app->make(SettingsSchemaRegistry::class)->registerSettingsClass('group', Settings::class);
        PHP,
    'promoted injection' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry;
        final class Provider {
            public function __construct(private SettingsSchemaRegistry $settings) {}
            public function boot(): void { $this->settings->register('group', Schema::class); }
        }
        PHP,
    'non-promoted injection assigned to another property' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry;
        final class Provider {
            private object $writer;
            public function __construct(SettingsSchemaRegistry $registry) { $this->writer = $registry; }
            public function boot(): void { $this->writer->replace('group', Schema::class, 'schema'); }
        }
        PHP,
    'aliased injection' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry as SchemaStore;
        final class Provider {
            public function __construct(private SchemaStore $store) {}
            public function boot(): void { $this->store->removeGroup('group'); }
        }
        PHP,
]);

/**
 * @return list<string>
 */
function settingsSchemaProductionPhpPaths(string $repositoryRoot): array
{
    $paths = [];
    $packages = new DirectoryIterator($repositoryRoot . '/packages');

    foreach ($packages as $package) {
        $sourceRoot = $package->getPathname() . '/src';

        if ($package->isDot() || ! is_dir($sourceRoot)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
    }

    sort($paths);

    return $paths;
}

function settingsSchemaSourceWritesToRegistry(string $contents): bool
{
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $statements = $traverser->traverse($statements);
    $nodes = (new NodeFinder)->findInstanceOf($statements, Node::class);
    $registryVariables = [];
    $registryProperties = [];

    foreach ($nodes as $node) {
        if (! $node instanceof Node\Param || ! settingsSchemaTypeIsRegistry($node->type)) {
            continue;
        }

        if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $registryVariables[$node->var->name] = true;

            if ($node->flags !== 0) {
                $registryProperties[$node->var->name] = true;
            }
        }
    }

    do {
        $knownCount = count($registryVariables) + count($registryProperties);

        foreach ($nodes as $node) {
            if (! $node instanceof Expr\Assign || ! settingsSchemaExpressionIsRegistry(
                $node->expr,
                $registryVariables,
                $registryProperties,
            )) {
                continue;
            }

            if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
                $registryVariables[$node->var->name] = true;
            }

            if (settingsSchemaPropertyName($node->var) !== null) {
                $registryProperties[settingsSchemaPropertyName($node->var)] = true;
            }
        }
    } while ($knownCount !== count($registryVariables) + count($registryProperties));

    foreach ($nodes as $node) {
        if (! $node instanceof Expr\MethodCall || ! $node->name instanceof Node\Identifier) {
            continue;
        }

        if (! in_array($node->name->toString(), [
            'register',
            'registerSettingsClass',
            'registerMetadata',
            'replace',
            'remove',
            'removeGroup',
        ], true)) {
            continue;
        }

        if (settingsSchemaExpressionIsRegistry($node->var, $registryVariables, $registryProperties)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<string, true>  $registryVariables
 * @param  array<string, true>  $registryProperties
 */
function settingsSchemaExpressionIsRegistry(
    Expr $expression,
    array $registryVariables,
    array $registryProperties,
): bool {
    if ($expression instanceof Expr\Variable && is_string($expression->name)) {
        return isset($registryVariables[$expression->name]);
    }

    $propertyName = settingsSchemaPropertyName($expression);

    if ($propertyName !== null) {
        return isset($registryProperties[$propertyName]);
    }

    if ($expression instanceof Expr\BinaryOp\Coalesce) {
        return settingsSchemaExpressionIsRegistry($expression->left, $registryVariables, $registryProperties)
            || settingsSchemaExpressionIsRegistry($expression->right, $registryVariables, $registryProperties);
    }

    if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
        return in_array($expression->name->toString(), ['app', 'resolve'], true)
            && settingsSchemaFirstArgumentIsRegistryClass($expression->args);
    }

    return $expression instanceof Expr\MethodCall
        && $expression->name instanceof Node\Identifier
        && $expression->name->toString() === 'make'
        && settingsSchemaFirstArgumentIsRegistryClass($expression->args);
}

function settingsSchemaPropertyName(Expr $expression): ?string
{
    if (! $expression instanceof Expr\PropertyFetch
        || ! $expression->var instanceof Expr\Variable
        || $expression->var->name !== 'this'
        || ! $expression->name instanceof Node\Identifier) {
        return null;
    }

    return $expression->name->toString();
}

function settingsSchemaTypeIsRegistry(Node\ComplexType|Node\Identifier|Node\Name|null $type): bool
{
    if ($type instanceof Node\NullableType) {
        return settingsSchemaTypeIsRegistry($type->type);
    }

    return $type instanceof Node\Name
        && ltrim($type->toString(), '\\') === 'Capell\Core\Support\Settings\SettingsSchemaRegistry';
}

/** @param array<Node\Arg> $arguments */
function settingsSchemaFirstArgumentIsRegistryClass(array $arguments): bool
{
    $class = $arguments[0]->value ?? null;

    return $class instanceof Expr\ClassConstFetch
        && $class->name instanceof Node\Identifier
        && $class->name->toString() === 'class'
        && $class->class instanceof Node\Name
        && ltrim($class->class->toString(), '\\') === 'Capell\Core\Support\Settings\SettingsSchemaRegistry';
}
