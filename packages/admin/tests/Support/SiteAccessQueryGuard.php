<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Activitylog\Models\Activity;

/** Reject authoring query origins unless the access boundary is visible. */
final class SiteAccessQueryGuard
{
    private const array MODELS = ['Site', 'SiteDomain', 'Page', 'PageUrl', 'Layout', 'Media', 'AssetAttachment', 'PublicRenderContractEvent', 'Taxonomy', 'Term', 'PagePropertyValue', 'EditorScratchDraft', 'ActivityBucket', 'ActivityVisitor', 'MetricDailyRollup', 'ContentGraphEdge', 'LayoutContentSnapshot', 'MetricEvent', 'Translation', 'TermPropertyValue', 'PageRevision', 'PageWorkflowState', 'ContentLock'];

    // These are relation origins, not collection terminals: reject even when
    // assigned first, so cursor/chunk or an aliased builder cannot evade the gate.
    private const array RELATIONS = ['pages', 'siteDomains', 'pageUrls', 'translations', 'propertyValues', 'terms', 'revisions', 'workflowState', 'contentLocks', 'assetRelations', 'children', 'descendants', 'ancestors', 'layouts', 'sites', 'media', 'activities', 'asset', 'assets', 'backgroundImage', 'canonicalPage', 'canonicalPages', 'defaultDomain', 'image', 'layout', 'logo', 'logoInverted', 'model', 'morphOneMedia', 'page', 'pageRevisions', 'pageUrl', 'pageable', 'parent', 'referencedPage', 'related', 'siblings', 'siblingsAndSelf', 'site', 'siteDomain', 'socialImage', 'taxonomy', 'term', 'translatable', 'translation'];

    // Applying an access scope after executing a query cannot authorise that
    // read or write. Builder composition may precede scope; execution may not.
    private const array EXECUTES_QUERY = ['all', 'get', 'first', 'firstOrFail', 'firstWhere', 'find', 'findOrFail', 'findMany', 'sole', 'count', 'min', 'max', 'sum', 'avg', 'average', 'exists', 'doesntExist', 'value', 'pluck', 'cursor', 'lazy', 'lazyById', 'lazyByIdDesc', 'chunk', 'chunkById', 'chunkByIdDesc', 'each', 'eachById', 'paginate', 'simplePaginate', 'cursorPaginate', 'insert', 'insertOrIgnore', 'insertUsing', 'update', 'updateOrInsert', 'updateOrCreate', 'create', 'createOrFirst', 'firstOrCreate', 'firstOrNew', 'delete', 'forceDelete', 'restore', 'truncate', 'upsert', 'increment', 'decrement', 'incrementEach', 'decrementEach'];

    /** @param list<string> $allowList Exact method/origin pairs, documented by the scanning test.
     * @return list<string>
     */
    public static function violations(string $source, array $allowList = []): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $nodes = new NodeTraverser(new NameResolver, new ParentConnectingVisitor)->traverse($nodes);

        $allowList = array_map(self::canonicalOrigin(...), $allowList);
        $finder = new NodeFinder;
        $violations = [];

        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof StaticCall || $node instanceof MethodCall) as $call) {
            if (! $call instanceof StaticCall && ! $call instanceof MethodCall) {
                continue;
            }

            if (! $call->name instanceof Identifier) {
                continue;
            }

            $methodContext = self::ancestor($call, ClassMethod::class);
            $rootCall = $call;
            while (($parentCall = $rootCall->getAttribute('parent')) instanceof MethodCall && $parentCall->var === $rootCall) {
                $rootCall = $parentCall;
            }

            $origin = ($methodContext instanceof ClassMethod ? $methodContext->name->toString() : 'global') . '|' . (new Standard)->prettyPrintExpr($rootCall);
            $origin = self::canonicalOrigin($origin);
            if (in_array($origin, $allowList, true)) {
                continue;
            }

            if (self::scoped($call)) {
                continue;
            }

            $method = $call->name->toString();
            if (in_array($method, ['__construct', 'getModel', 'make'], true)) {
                continue;
            }

            if ($call instanceof StaticCall) {
                $class = self::className($call->class, $call, $finder, $nodes);
                if (in_array($class, [DB::class, 'DB'], true) && $method === 'table') {
                    $table = $call->args[0]->value ?? null;
                    if (! $table instanceof String_ || self::siteTable($table->value)) {
                        $violations[] = 'DB::table at line ' . $call->getStartLine() . ' [' . $origin . ']';
                    }
                } elseif (self::queryMethod($method) && (self::siteModel($class) !== null || self::unknownModelClass($class))) {
                    $violations[] = (self::siteModel($class) ?? 'unresolved model') . '::' . $method . ' at line ' . $call->getStartLine() . ' [' . $origin . ']';
                }
            } elseif ($method === 'table' && $call->var instanceof StaticCall
                && $call->var->class instanceof Name && in_array($call->var->class->toString(), [DB::class, 'DB'], true)) {
                $table = $call->args[0]->value ?? null;
                if (! $table instanceof String_ || self::siteTable($table->value)) {
                    $violations[] = 'unscoped raw table at line ' . $call->getStartLine() . ' [' . $origin . ']';
                }
            } elseif (in_array($method, ['newQuery', 'newModelQuery', 'newQueryWithoutScopes', 'newQueryForRestoration'], true)) {
                $class = self::className($call->var, $call, $finder, $nodes);
                if (self::unknownModelClass($class) || self::siteModel($class) !== null) {
                    $violations[] = 'unscoped instance ' . $method . ' at line ' . $call->getStartLine() . ' [' . $origin . ']';
                }
            } elseif (in_array($method, self::RELATIONS, true) && ! self::localHelper($call)
                && ! self::nonRelationHelper($call, $finder, $nodes)) {
                $violations[] = 'unscoped relation ' . $method . ' at line ' . $call->getStartLine() . ' [' . $origin . ']';
            }
        }

        foreach ($finder->findInstanceOf($nodes, Class_::class) as $class) {
            if ($class->extends?->toString() !== Resource::class) {
                continue;
            }

            $modelDeclarations = array_filter($class->stmts, static fn (Stmt $statement): bool => $statement instanceof Property
                ? array_filter($statement->props, static fn (PropertyItem $property): bool => $property->name->toString() === 'model') !== []
                : $statement instanceof ClassMethod && $statement->name->toString() === 'getModel');
            foreach ($finder->find($modelDeclarations, static fn (Node $node): bool => $node instanceof ClassConstFetch || $node instanceof String_) as $constant) {
                if (self::siteModel(self::className($constant, $constant, $finder, $nodes)) !== null) {
                    $violations[] = ($class->name?->toString() ?? 'anonymous resource') . ' must extend SiteScopedResource';
                    break;
                }
            }
        }

        return $violations;
    }

    private static function canonicalOrigin(string $origin): string
    {
        [$method, $expression] = explode('|', $origin, 2);
        $tokens = token_get_all('<?php ' . $expression);
        $normalised = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_OPEN_TAG) {
                    continue;
                }

                $normalised .= $token[0] === T_NAME_FULLY_QUALIFIED ? ltrim($token[1], '\\') : $token[1];
            } else {
                $normalised .= $token;
            }
        }

        return $method . '|' . $normalised;
    }

    private static function queryMethod(string $method): bool
    {
        return method_exists(Builder::class, $method) || method_exists(QueryBuilder::class, $method)
            || in_array($method, ['query', 'all', 'getDefault', 'loadOptions', 'onlyTrashed', 'withTrashed', 'withoutTrashed'], true);
    }

    private static function siteModel(?string $class): ?string
    {
        if ($class !== null && is_a($class, Activity::class, true)) {
            return 'Activity';
        }

        $prefix = 'Capell\\Core\\Models\\';
        $model = $class !== null && str_starts_with($class, $prefix) ? substr($class, strlen($prefix)) : '';

        if (in_array($model, self::MODELS, true)) {
            return $model;
        }

        if ($class !== null && is_a($class, Model::class, true)) {
            // Registered aliases and host page variations inherit ownership;
            // their namespace must not turn a protected model into global data.
            foreach (self::MODELS as $owned) {
                if (is_a($class, 'Capell\\Core\\Models\\' . $owned, true)) {
                    return $owned;
                }
            }

            $fillable = new ReflectionClass($class)->getDefaultProperties()['fillable'] ?? [];
            if (is_array($fillable) && in_array('site_id', $fillable, true)) {
                return $class;
            }
        }

        return null;
    }

    private static function siteTable(string $table): bool
    {
        // Aliases, quoted identifiers and schema qualification do not change
        // ownership. Inspect the underlying table rather than the SQL spelling.
        $parts = preg_split('/\s+/', trim($table), 2) ?: [''];
        $qualified = explode('.', $parts[0]);
        $table = strtolower(trim($qualified[array_key_last($qualified)], '`"[]'));

        foreach (self::MODELS as $model) {
            $class = 'Capell\\Core\\Models\\' . $model;
            if ($table === (new $class)->getTable()) {
                return true;
            }
        }

        return in_array($table, ['activity_log', 'page_term'], true);
    }

    private static function unknownModelClass(?string $class): bool
    {
        // An unresolved class, base-model or interface does not establish ownership.
        return $class === null || ! class_exists($class)
            || is_a($class, Model::class, true) && new ReflectionClass($class)->isAbstract();
    }

    /** @param array<Node> $nodes */
    private static function className(Node $expression, Node $at, NodeFinder $finder, array $nodes): ?string
    {
        if ($expression instanceof Name) {
            return in_array($expression->toString(), ['self', 'static', 'parent'], true) ? null : $expression->toString();
        }

        if ($expression instanceof String_) {
            return ltrim($expression->value, '\\');
        }

        if ($expression instanceof ClassConstFetch || $expression instanceof New_) {
            return self::className($expression->class, $at, $finder, $nodes);
        }

        if ($expression instanceof StaticCall || $expression instanceof MethodCall) {
            $class = self::className($expression instanceof StaticCall ? $expression->class : $expression->var, $at, $finder, $nodes);
            if ($class !== null && $expression->name instanceof Identifier && method_exists($class, $expression->name->toString())) {
                $type = new ReflectionMethod($class, $expression->name->toString())->getReturnType();

                return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
                    ? (in_array($type->getName(), ['self', 'static'], true) ? $class : $type->getName()) : null;
            }
        }

        if ($expression instanceof PropertyFetch && $expression->name instanceof Identifier) {
            $class = self::ancestor($at, Class_::class);
            if ($expression->var instanceof Variable && $expression->var->name === 'this' && $class instanceof Class_) {
                foreach ($class->stmts as $statement) {
                    if ($statement instanceof Property && $statement->type instanceof Name) {
                        foreach ($statement->props as $property) {
                            if ($property->name->toString() === $expression->name->toString()) {
                                return $statement->type->toString();
                            }
                        }
                    }
                }

                $constructor = $class->getMethod('__construct');
                foreach ($constructor instanceof ClassMethod ? $constructor->params : [] as $parameter) {
                    if ($parameter->flags !== 0 && $parameter->var instanceof Variable
                        && $parameter->var->name === $expression->name->toString() && $parameter->type instanceof Name) {
                        return $parameter->type->toString();
                    }
                }
            }
        }

        if ($expression instanceof StaticPropertyFetch) {
            // A default does not establish this query's class: static properties
            // can be reassigned, overridden, or belong to another receiver.
            // Require a visible access boundary or an exact system exception.
            return null;
        }

        if ($expression instanceof Variable && is_string($expression->name)) {
            if ($expression->name === 'this') {
                $class = self::ancestor($at, Class_::class);

                return $class instanceof Class_ ? ($class->namespacedName?->toString() ?? $class->name?->toString()) : null;
            }

            $scope = self::lexicalScope($at);
            $bound = null;
            if ($scope !== null) {
                foreach ($scope->getParams() as $param) {
                    if ($param instanceof Param && $param->var instanceof Variable && $param->var->name === $expression->name && $param->type instanceof Name) {
                        $bound = $param->type->toString();
                    }
                }
            }

            foreach ($finder->findInstanceOf($scope?->getStmts() ?? $nodes, Assign::class) as $assignment) {
                if ($assignment->var instanceof Variable && $assignment->var->name === $expression->name
                    && $assignment->getStartFilePos() < $at->getStartFilePos()) {
                    $bound = self::className($assignment->expr, $assignment, $finder, $nodes);
                }
            }

            return $bound;
        }

        return null;
    }

    private static function lexicalScope(Node $node): ClassMethod|Closure|ArrowFunction|Function_|null
    {
        while (($parent = $node->getAttribute('parent')) instanceof Node) {
            if ($parent instanceof ClassMethod || $parent instanceof Closure || $parent instanceof ArrowFunction || $parent instanceof Function_) {
                return $parent;
            }

            $node = $parent;
        }

        return null;
    }

    /** @param array<Node> $nodes */
    private static function nonRelationHelper(MethodCall $call, NodeFinder $finder, array $nodes): bool
    {
        $class = self::className($call->var, $call, $finder, $nodes);
        if ($class === null || ! $call->name instanceof Identifier || ! method_exists($class, $call->name->toString())) {
            return false;
        }

        $type = new ReflectionMethod($class, $call->name->toString())->getReturnType();
        if (! $type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return in_array($type->getName(), ['array', 'string', 'int', 'float', 'bool', 'void', 'never'], true);
        }

        // Filament setters and authorised record helpers share relation names.
        // Only an established non-builder return type distinguishes those APIs;
        // an unknown, mixed or relation/builder return remains rejected.
        $return = in_array($type->getName(), ['self', 'static'], true) ? $class : $type->getName();

        return ! is_a($return, Relation::class, true)
            && ! is_a($return, Builder::class, true) && ! is_a($return, QueryBuilder::class, true);
    }

    private static function scoped(Node $node): bool
    {
        $executed = self::executesQuery($node);
        while (($parent = $node->getAttribute('parent')) instanceof Node) {
            if ($parent instanceof MethodCall && $parent->name instanceof Identifier && in_array($parent->name->toString(), ['scope', 'scopeMedia', 'scopeAssetAttachments'], true)) {
                if ($executed) {
                    return false;
                }

                $receiver = $parent->var;
                if ($receiver instanceof StaticCall && $receiver->class instanceof Name
                    && $receiver->class->toString() === SiteAccess::class) {
                    return true;
                }

                // A typed/assigned SiteAccess variable is also a visible boundary.
                if ($receiver instanceof Variable && self::accessVariable($receiver, $parent)) {
                    return true;
                }
            }

            $executed = $executed || self::executesQuery($parent);
            $node = $parent;
        }

        return false;
    }

    private static function executesQuery(Node $node): bool
    {
        return ($node instanceof StaticCall || $node instanceof MethodCall)
            && $node->name instanceof Identifier && in_array($node->name->toString(), self::EXECUTES_QUERY, true);
    }

    private static function accessVariable(Variable $variable, Node $at): bool
    {
        $scope = self::ancestor($at, ClassMethod::class);
        if (! $scope instanceof ClassMethod) {
            return false;
        }

        $valid = false;
        foreach ($scope->params as $param) {
            if ($param->var instanceof Variable && $param->var->name === $variable->name && $param->type instanceof Name
                && $param->type->toString() === SiteAccess::class) {
                $valid = true;
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($scope->stmts ?? [], Assign::class) as $assignment) {
            if ($assignment->var instanceof Variable && $assignment->var->name === $variable->name
                && $assignment->getStartFilePos() < $at->getStartFilePos()) {
                $valid = $assignment->expr instanceof StaticCall && $assignment->expr->class instanceof Name
                    && $assignment->expr->class->toString() === SiteAccess::class
                    && $assignment->expr->name instanceof Identifier && in_array($assignment->expr->name->toString(), ['current', 'forActor'], true);
            }
        }

        return $valid;
    }

    private static function localHelper(MethodCall $call): bool
    {
        if (! $call->var instanceof Variable || $call->var->name !== 'this') {
            return false;
        }

        $class = self::ancestor($call, Class_::class);

        $method = $class instanceof Class_ && $call->name instanceof Identifier ? $class->getMethod($call->name->toString()) : null;

        // A declared scalar/collection result cannot be an Eloquent relation;
        // query origins inside the helper body are still scanned independently.
        return $method instanceof ClassMethod && ($method->returnType instanceof Identifier && in_array($method->returnType->toString(), ['array', 'string', 'int', 'float', 'bool', 'void', 'never'], true)
            || $method->returnType instanceof Name && $method->returnType->toString() === Collection::class);
    }

    /** @param class-string<Node> $type */
    private static function ancestor(Node $node, string $type): ?Node
    {
        while (($parent = $node->getAttribute('parent')) instanceof Node) {
            if ($parent instanceof $type) {
                return $parent;
            }

            $node = $parent;
        }

        return null;
    }
}
