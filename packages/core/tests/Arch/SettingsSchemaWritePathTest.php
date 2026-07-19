<?php

declare(strict_types=1);

use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
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
    'typed accessor receiver' => <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry as SchemaStore;
        final class Provider {
            private function settings(): SchemaStore { return resolve(SchemaStore::class); }
            public function boot(): void { $this->settings()->register('group', Schema::class); }
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
        if ($package->isDot()) {
            continue;
        }

        if (! is_dir($sourceRoot)) {
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
    $registryAccessors = [];

    foreach ($nodes as $node) {
        if ($node instanceof ClassMethod
            && settingsSchemaTypeIsRegistry($node->returnType)) {
            $registryAccessors[$node->name->toString()] = true;
        }

        if (! $node instanceof Param) {
            continue;
        }

        if (! settingsSchemaTypeIsRegistry($node->type)) {
            continue;
        }

        if ($node->var instanceof Variable && is_string($node->var->name)) {
            $registryVariables[$node->var->name] = true;

            if ($node->flags !== 0) {
                $registryProperties[$node->var->name] = true;
            }
        }
    }

    do {
        $knownCount = count($registryVariables) + count($registryProperties);

        foreach ($nodes as $node) {
            if (! $node instanceof Assign) {
                continue;
            }

            if (! settingsSchemaExpressionIsRegistry(
                $node->expr,
                $registryVariables,
                $registryProperties,
                $registryAccessors,
            )) {
                continue;
            }

            if ($node->var instanceof Variable && is_string($node->var->name)) {
                $registryVariables[$node->var->name] = true;
            }

            if (settingsSchemaPropertyName($node->var) !== null) {
                $registryProperties[settingsSchemaPropertyName($node->var)] = true;
            }
        }
    } while ($knownCount !== count($registryVariables) + count($registryProperties));

    foreach ($nodes as $node) {
        if (! $node instanceof MethodCall) {
            continue;
        }

        if (! $node->name instanceof Identifier) {
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

        if (settingsSchemaExpressionIsRegistry(
            $node->var,
            $registryVariables,
            $registryProperties,
            $registryAccessors,
        )) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<string, true>  $registryVariables
 * @param  array<string, true>  $registryProperties
 * @param  array<string, true>  $registryAccessors
 */
function settingsSchemaExpressionIsRegistry(
    Expr $expression,
    array $registryVariables,
    array $registryProperties,
    array $registryAccessors,
): bool {
    if ($expression instanceof Variable && is_string($expression->name)) {
        return isset($registryVariables[$expression->name]);
    }

    $propertyName = settingsSchemaPropertyName($expression);

    if ($propertyName !== null) {
        return isset($registryProperties[$propertyName]);
    }

    if ($expression instanceof Coalesce) {
        return settingsSchemaExpressionIsRegistry(
            $expression->left,
            $registryVariables,
            $registryProperties,
            $registryAccessors,
        ) || settingsSchemaExpressionIsRegistry(
            $expression->right,
            $registryVariables,
            $registryProperties,
            $registryAccessors,
        );
    }

    if ($expression instanceof FuncCall && $expression->name instanceof Name) {
        return in_array($expression->name->toString(), ['app', 'resolve'], true)
            && settingsSchemaFirstArgumentIsRegistryClass($expression->args);
    }

    if (! $expression instanceof MethodCall || ! $expression->name instanceof Identifier) {
        return false;
    }

    if ($expression->name->toString() === 'make') {
        return settingsSchemaFirstArgumentIsRegistryClass($expression->args);
    }

    return $expression->var instanceof Variable
        && $expression->var->name === 'this'
        && isset($registryAccessors[$expression->name->toString()]);
}

function settingsSchemaPropertyName(Expr $expression): ?string
{
    if (! $expression instanceof PropertyFetch
        || ! $expression->var instanceof Variable
        || $expression->var->name !== 'this'
        || ! $expression->name instanceof Identifier) {
        return null;
    }

    return $expression->name->toString();
}

function settingsSchemaTypeIsRegistry(ComplexType|Identifier|Name|null $type): bool
{
    if ($type instanceof NullableType) {
        return settingsSchemaTypeIsRegistry($type->type);
    }

    return $type instanceof Name
        && ltrim($type->toString(), '\\') === SettingsSchemaRegistry::class;
}

/** @param array<Node\Arg> $arguments */
function settingsSchemaFirstArgumentIsRegistryClass(array $arguments): bool
{
    $class = $arguments[0]->value ?? null;

    return $class instanceof ClassConstFetch
        && $class->name instanceof Identifier
        && $class->name->toString() === 'class'
        && $class->class instanceof Name
        && ltrim($class->class->toString(), '\\') === SettingsSchemaRegistry::class;
}
