<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class SiteAccessQueryGuard
{
    private const array MODELS = ['Site', 'SiteDomain', 'Page', 'PageUrl', 'Layout', 'Media', 'AssetAttachment', 'PublicRenderContractEvent', 'Taxonomy', 'Term', 'PagePropertyValue', 'EditorScratchDraft', 'ActivityBucket', 'ActivityVisitor', 'MetricDailyRollup', 'ContentGraphEdge', 'LayoutContentSnapshot', 'MetricEvent'];

    /** @return list<string> */
    public static function violations(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $nodes = new NodeTraverser(new NameResolver)->traverse($nodes);

        $finder = new NodeFinder;
        $calls = $finder->findInstanceOf($nodes, StaticCall::class);
        $violations = [];
        $bindings = [];

        foreach ($finder->findInstanceOf($nodes, Assign::class) as $assignment) {
            if ($assignment->var instanceof Variable && is_string($assignment->var->name)
                && $assignment->expr instanceof ClassConstFetch && $assignment->expr->class instanceof Name) {
                $bindings[$assignment->var->name][] = [$assignment->getStartLine(), $assignment->expr->class->toString()];
            }
        }

        foreach ($calls as $call) {
            if (! $call->name instanceof Identifier) {
                continue;
            }

            $class = $call->class instanceof Name ? $call->class->toString() : '';
            if ($call->class instanceof Variable && is_string($call->class->name)) {
                foreach ($bindings[$call->class->name] ?? [] as [$line, $boundClass]) {
                    if ($line <= $call->getStartLine()) {
                        $class = $boundClass;
                    }
                }
            }

            $model = self::siteModel($class);
            if ($model === null) {
                continue;
            }

            $method = $call->name->toString();
            // Eloquent forwards query methods and registers soft-delete macros;
            // checking only ::query() leaves shortcuts such as ::onlyTrashed() open.
            if (method_exists(Builder::class, $method) || method_exists(QueryBuilder::class, $method)
                || in_array($method, ['query', 'all', 'getDefault', 'loadOptions', 'onlyTrashed', 'withTrashed', 'withoutTrashed'], true)) {
                $violations[] = $model . '::' . $call->name->toString() . ' at line ' . $call->getStartLine();
            }
        }

        // Inherited Filament queries are otherwise invisible to a query-origin
        // scan. A resource declaring a site-owned model must inherit the scope.
        foreach ($finder->findInstanceOf($nodes, Class_::class) as $class) {
            if ($class->extends?->toString() !== Resource::class) {
                continue;
            }

            $modelDeclarations = array_filter($class->stmts, static fn (Stmt $statement): bool => $statement instanceof Property
                ? array_filter($statement->props, static fn (PropertyItem $property): bool => $property->name->toString() === 'model') !== []
                : $statement instanceof ClassMethod && $statement->name->toString() === 'getModel');
            foreach ($finder->findInstanceOf($modelDeclarations, ClassConstFetch::class) as $constant) {
                if ($constant->class instanceof Name && self::siteModel($constant->class->toString()) !== null) {
                    $violations[] = ($class->name?->toString() ?? 'anonymous resource') . ' must extend SiteScopedResource';
                    break;
                }
            }
        }

        return $violations;
    }

    private static function siteModel(string $class): ?string
    {
        $prefix = 'Capell\\Core\\Models\\';
        $model = str_starts_with($class, $prefix) ? substr($class, strlen($prefix)) : '';

        return in_array($model, self::MODELS, true) ? $model : null;
    }
}
