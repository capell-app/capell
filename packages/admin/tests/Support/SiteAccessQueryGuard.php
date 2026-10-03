<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
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
use ReflectionUnionType;
use Spatie\Activitylog\Models\Activity;

/** Reject authoring query origins unless the access boundary is visible. */
final class SiteAccessQueryGuard
{
    private const array DIRECTORIES = ['packages/admin/src/Filament', 'packages/admin/src/Actions', 'packages/admin/src/Support', 'packages/admin/src/Http/Controllers', 'packages/marketplace/src/Filament', 'packages/marketplace/src/Actions', 'packages/marketplace/src/Jobs', 'packages/admin/src/Livewire', 'packages/admin/src/Macros', 'packages/admin/src/Http/Agent', 'packages/admin/src/Http/Middleware', 'packages/admin/src/Jobs', 'packages/admin/src/Providers', 'packages/admin/src/Data', 'packages/marketplace/src/Support', 'packages/marketplace/src/Http', 'packages/marketplace/src/Livewire'];

    private const array MODELS = ['Site', 'SiteDomain', 'Page', 'PageUrl', 'Layout', 'Media', 'AssetAttachment', 'PublicRenderContractEvent', 'Taxonomy', 'Term', 'PagePropertyValue', 'EditorScratchDraft', 'ActivityBucket', 'ActivityVisitor', 'MetricDailyRollup', 'ContentGraphEdge', 'LayoutContentSnapshot', 'MetricEvent', 'Translation', 'TermPropertyValue', 'PageRevision', 'PageWorkflowState', 'ContentLock'];

    // These are relation origins, not collection terminals: reject even when
    // assigned first, so cursor/chunk or an aliased builder cannot evade the gate.
    private const array RELATIONS = ['pages', 'siteDomains', 'pageUrls', 'translations', 'propertyValues', 'terms', 'revisions', 'workflowState', 'contentLocks', 'assetRelations', 'children', 'descendants', 'ancestors', 'layouts', 'sites', 'media', 'activities', 'asset', 'assets', 'backgroundImage', 'canonicalPage', 'canonicalPages', 'defaultDomain', 'image', 'layout', 'logo', 'logoInverted', 'model', 'morphOneMedia', 'page', 'pageRevisions', 'pageUrl', 'pageable', 'parent', 'referencedPage', 'related', 'siblings', 'siblingsAndSelf', 'site', 'siteDomain', 'socialImage', 'taxonomy', 'term', 'translatable', 'translation'];

    // Applying an access scope after executing a query cannot authorise that
    // read or write. Builder composition may precede scope; execution may not.
    private const array EXECUTES_QUERY = ['load', 'loadMissing', 'loadCount', 'loadAggregate', 'loadSum', 'loadAvg', 'loadMax', 'loadMin', 'loadExists', 'getRelationValue', 'getRelation', 'all', 'get', 'first', 'firstOrFail', 'firstWhere', 'find', 'findOrFail', 'findMany', 'sole', 'count', 'min', 'max', 'sum', 'avg', 'average', 'exists', 'doesntExist', 'value', 'pluck', 'cursor', 'lazy', 'lazyById', 'lazyByIdDesc', 'chunk', 'chunkById', 'chunkByIdDesc', 'each', 'eachById', 'paginate', 'simplePaginate', 'cursorPaginate', 'insert', 'insertOrIgnore', 'insertUsing', 'update', 'updateOrInsert', 'updateOrCreate', 'create', 'createOrFirst', 'firstOrCreate', 'firstOrNew', 'delete', 'forceDelete', 'restore', 'truncate', 'upsert', 'increment', 'decrement', 'incrementEach', 'decrementEach'];

    /** @var array<class-string<Model>, array<string, array{target: ?string, foreignKey: ?string, childKey: ?string, many: bool, ownerRelation: ?string}>>|null */
    private static ?array $modelRelations = null;

    /**
     * @param  array<string, list<string>>  $allowList
     * @return list<string>
     */
    public static function scan(string $root, array $allowList = []): array
    {
        $violations = [];
        foreach (self::DIRECTORIES as $directory) {
            if (! File::isDirectory($root . '/' . $directory)) {
                continue;
            }

            foreach (File::allFiles($root . '/' . $directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $directory . '/' . $file->getRelativePathname();
                foreach (self::violations($file->getContents(), $allowList[$path] ?? []) as $violation) {
                    $violations[] = $path . ': ' . $violation;
                }
            }
        }

        return $violations;
    }

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

        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof StaticCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) as $call) {
            if (! $call instanceof StaticCall && ! $call instanceof MethodCall && ! $call instanceof NullsafeMethodCall && ! $call instanceof PropertyFetch && ! $call instanceof NullsafePropertyFetch) {
                continue;
            }

            if (! $call->name instanceof Identifier) {
                continue;
            }

            $methodContext = self::ancestor($call, ClassMethod::class);
            $rootCall = $call;
            while ((($parentCall = $rootCall->getAttribute('parent')) instanceof MethodCall || $parentCall instanceof NullsafeMethodCall || $parentCall instanceof PropertyFetch || $parentCall instanceof NullsafePropertyFetch) && $parentCall->var === $rootCall) {
                $rootCall = $parentCall;
            }

            $origin = ($methodContext instanceof ClassMethod ? $methodContext->name->toString() : 'global') . '|' . (new Standard)->prettyPrintExpr($rootCall);
            $origin = self::canonicalOrigin($origin);
            if (in_array($origin, $allowList, true)) {
                continue;
            }

            if ($call instanceof PropertyFetch || $call instanceof NullsafePropertyFetch) {
                $class = self::className($call->var, $call, $finder, $nodes);
                if (self::crossSiteRelation($class, $call->name->toString())) {
                    $violations[] = 'lazy relation property at line ' . $call->getStartLine() . ' [' . $origin . ']';
                }

                continue;
            }

            if (in_array($call->name->toString(), ['with', 'withCount', 'withExists', 'withAggregate', 'withSum', 'withAvg', 'withMax', 'withMin', 'has', 'orHas', 'doesntHave', 'whereHas', 'orWhereHas', 'whereDoesntHave', 'whereRelation', 'load', 'loadMissing', 'loadCount', 'loadAggregate', 'loadSum', 'loadAvg', 'loadMax', 'loadMin', 'loadExists', 'getRelationValue', 'getRelation'], true)) {
                $class = self::className($call instanceof StaticCall ? $call->class : $call->var, $call, $finder, $nodes);
                $arguments = in_array($call->name->toString(), ['with', 'load', 'loadMissing'], true) ? $call->args : array_slice($call->args, 0, 1);
                foreach (self::relationStrings($arguments) as [$relation, $scopedRelation]) {
                    if ($scopedRelation) {
                        continue;
                    }

                    if ($class !== null && class_exists($class) && ! is_a($class, Model::class, true)
                        && ! is_a($class, Builder::class, true) && ! is_a($class, Relation::class, true)) {
                        continue;
                    }

                    $path = preg_split('/[ :]/', $relation)[0] ?? '';
                    $receiver = $class;
                    foreach (explode('.', $path) as $name) {
                        if (self::crossSiteRelation($receiver, $name)) {
                            $violations[] = 'string relation ' . $path . ' at line ' . $call->getStartLine() . ' [' . $origin . ']';
                            break 2;
                        }

                        $receiver = self::relationTarget($receiver, $name);
                    }
                }
            }

            if (($call instanceof MethodCall || $call instanceof NullsafeMethodCall)
                && in_array($call->name->toString(), ['scope', 'scopeMedia', 'scopeAssetAttachments'], true)
                && self::className($call->var, $call, $finder, $nodes) === SiteAccess::class) {
                $argument = $call->args[0] ?? null;
                if ($argument instanceof Arg && self::executesQuery($argument->value)) {
                    $violations[] = 'scope after execution at line ' . $call->getStartLine() . ' [' . $origin . ']';
                }
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
            } elseif (in_array($method, self::RELATIONS, true)
                && (self::unknownModelClass(self::className($call->var, $call, $finder, $nodes))
                    || self::crossSiteRelation(self::className($call->var, $call, $finder, $nodes), $method)) && ! self::localHelper($call)
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

    /** @param array<Node> $arguments
     * @return list<array{string, bool}>
     */
    private static function relationStrings(array $arguments): array
    {
        $relations = [];
        foreach ($arguments as $argument) {
            if (! $argument instanceof Arg) {
                continue;
            }

            if ($argument->value instanceof String_) {
                $relations[] = [$argument->value->value, false];
            } elseif ($argument->value instanceof Array_) {
                foreach ($argument->value->items as $item) {
                    if ($item === null) {
                        continue;
                    }

                    $name = $item->key ?? $item->value;
                    if ($name instanceof String_) {
                        $scoped = false;
                        $callback = $item->value;
                        if ($item->key instanceof String_ && $callback instanceof ArrowFunction
                            && $callback->expr instanceof MethodCall && $callback->expr->name instanceof Identifier
                            && in_array($callback->expr->name->toString(), ['scope', 'scopeMedia', 'scopeAssetAttachments'], true)) {
                            $value = $callback->expr->args[0] ?? null;
                            $parameter = $callback->params[0]->var ?? null;
                            $scoped = $value instanceof Arg && $value->value instanceof Variable
                                && $parameter instanceof Variable && $parameter->name === $value->value->name && self::scoped($value->value);
                        }

                        $relations[] = [$name->value, $scoped];
                    }
                }
            }
        }

        return $relations;
    }

    /**
     * Read relation definitions without invoking them. This includes inherited
     * trait relations and host morph registrations, rather than a name baseline.
     *
     * @return array<class-string<Model>, array<string, array{target: ?string, foreignKey: ?string, childKey: ?string, many: bool, ownerRelation: ?string}>>
     */
    private static function modelRelations(): array
    {
        if (self::$modelRelations !== null) {
            return self::$modelRelations;
        }

        $models = array_values(Relation::morphMap());
        foreach (File::allFiles(dirname(__DIR__, 3) . '/core/src/Models') as $file) {
            $class = 'Capell\\Core\\Models\\' . $file->getBasename('.php');
            if ($file->getExtension() === 'php' && is_subclass_of($class, Model::class)) {
                $models[] = $class;
            }
        }

        $relations = [];
        $sources = [];
        $finder = new NodeFinder;
        foreach (array_unique($models) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $relations[$class] = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
                if ($method->getDeclaringClass()->getName() === Model::class) {
                    continue;
                }

                if ($method->isVariadic()) {
                    continue;
                }

                if ($method->getNumberOfRequiredParameters() !== 0) {
                    continue;
                }

                if (! array_any(
                    $types,
                    static fn (mixed $type): bool => $type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true),
                )) {
                    continue;
                }

                $file = $method->getFileName();
                if ($file === false) {
                    continue;
                }

                if (! isset($sources[$file])) {
                    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse(File::get($file)) ?? [];
                    $sources[$file] = new NodeTraverser(new NameResolver)->traverse($parsed);
                }

                $definition = $finder->findFirst($sources[$file], static fn (Node $node): bool => $node instanceof ClassMethod && $node->getStartLine() === $method->getStartLine());
                $constructor = $definition instanceof ClassMethod ? $finder->findFirst($definition->stmts ?? [], static fn (Node $node): bool => $node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === 'this'
                    && $node->name instanceof Identifier && method_exists(Model::class, $node->name->toString())
                    && in_array($node->name->toString(), ['hasOne', 'hasMany', 'hasOneThrough', 'hasManyThrough', 'belongsTo', 'belongsToMany', 'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany'], true)) : null;
                $target = null;
                $foreignKey = null;
                $childKey = null;
                $many = array_any($types, static fn (mixed $type): bool => $type instanceof ReflectionNamedType && preg_match('/Many|Descendants|Ancestors/', $type->getName()) === 1);
                $ownerRelation = null;
                if ($constructor instanceof MethodCall) {
                    $argument = $constructor->args[0] ?? null;
                    if ($argument instanceof Arg && $argument->value instanceof ClassConstFetch && $argument->value->class instanceof Name) {
                        $target = $argument->value->class->toString();
                        $target = in_array($target, ['self', 'static'], true) ? $class : $target;
                    }

                    if ($constructor->name instanceof Identifier && $constructor->name->toString() === 'belongsTo') {
                        $key = $constructor->args[1] ?? null;
                        $foreignKey = $key instanceof Arg && $key->value instanceof String_ ? $key->value->value : Str::snake($method->getName()) . '_id';
                    }

                    if ($constructor->name instanceof Identifier && in_array($constructor->name->toString(), ['hasOne', 'hasMany'], true)) {
                        $key = $constructor->args[1] ?? null;
                        $childKey = $key instanceof Arg && $key->value instanceof String_ ? $key->value->value : Str::snake(class_basename($class)) . '_id';
                        $local = $constructor->args[2] ?? null;
                        if ($key instanceof Arg && $key->value instanceof Array_ && $local instanceof Arg && $local->value instanceof Array_) {
                            foreach ($key->value->items as $index => $item) {
                                $localItem = $local->value->items[$index] ?? null;
                                if ($item?->value instanceof String_ && $item->value->value === 'site_id'
                                    && $localItem?->value instanceof String_ && $localItem->value->value === 'site_id') {
                                    $childKey = 'site_id';
                                }
                            }
                        }
                    }

                    if ($constructor->name instanceof Identifier && in_array($constructor->name->toString(), ['morphOne', 'morphMany'], true)) {
                        $owner = $constructor->args[1] ?? null;
                        $ownerRelation = $owner instanceof Arg && $owner->value instanceof String_ ? $owner->value->value : null;
                    }
                }

                $relations[$class][$method->getName()] = ['target' => $target, 'foreignKey' => $foreignKey, 'childKey' => $childKey, 'many' => $many, 'ownerRelation' => $ownerRelation];
            }
        }

        return self::$modelRelations = $relations;
    }

    /** @return array{target: ?string, foreignKey: ?string, childKey: ?string, many: bool, ownerRelation: ?string}|null */
    private static function relation(?string $class, string $name): ?array
    {
        if ($class !== null && is_a($class, Pageable::class, true) && ! class_exists($class)) {
            $class = Page::class;
        }

        foreach (self::modelRelations() as $model => $relations) {
            if ($class !== null && is_a($class, $model, true)) {
                return $relations[$name] ?? null;
            }
        }

        return null;
    }

    private static function relationTarget(?string $class, string $name): ?string
    {
        $relation = self::relation($class, $name);

        return $relation['target'] ?? null;
    }

    /** @param list<string> $visited */
    private static function singleSite(string $class, array $visited = []): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        if (is_a($class, Site::class, true)) {
            return true;
        }

        if (in_array($class, $visited, true)) {
            return false;
        }

        $reflection = new ReflectionClass($class);
        $doc = $reflection->getDocComment() ?: '';
        if (preg_match('/@property\s+([^\s]+)\s+\$site_id\b/', $doc, $match) === 1) {
            return ! str_contains($match[1], 'null') && ! str_contains($match[1], '?');
        }

        foreach (self::modelRelations()[$class] ?? [] as $relation) {
            if ($relation['foreignKey'] !== null && $relation['target'] !== null
                && preg_match('/@property\s+([^\s]+)\s+\$' . preg_quote($relation['foreignKey'], '/') . '\b/', $doc, $match) === 1
                && ! str_contains($match[1], 'null') && ! str_contains($match[1], '?')
                && self::singleSite($relation['target'], [...$visited, $class])) {
                return true;
            }
        }

        return false;
    }

    private static function crossSiteRelation(?string $class, string $name): bool
    {
        if ($class !== null && is_a($class, Pageable::class, true)) {
            $class = Page::class;
        }

        if ($class !== null && ! self::unknownModelClass($class)) {
            if (! is_a($class, Model::class, true)) {
                return false;
            }

            $relation = self::relation($class, $name);

            return $relation !== null && self::spansSites($relation) && ! self::ownedChild($class, $relation);
        }

        foreach (self::modelRelations() as $model => $relations) {
            $relation = $relations[$name] ?? null;
            if ($relation !== null && self::spansSites($relation) && ! self::ownedChild($model, $relation)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{target: ?string, foreignKey: ?string, childKey: ?string, many: bool, ownerRelation: ?string} $relation */
    private static function spansSites(array $relation): bool
    {
        if (! $relation['many'] || self::siteModel($relation['target']) === null) {
            return false;
        }

        // Owner lookups and an owner's own morph children do not widen its
        // visibility. Usage backlinks do: their owner is the referring record.
        return $relation['ownerRelation'] === null || $relation['target'] === null
            || self::singleSite($relation['target']);
    }

    /** @param array{target: ?string, foreignKey: ?string, childKey: ?string, many: bool, ownerRelation: ?string} $relation */
    private static function ownedChild(string $class, array $relation): bool
    {
        $target = $relation['target'];
        if ($target === null || ! class_exists($target) || ! self::singleSite($class)) {
            return false;
        }

        $doc = new ReflectionClass($target)->getDocComment() ?: '';
        if ($relation['childKey'] === 'site_id') {
            return true;
        }

        // Page URLs have an inverse pageable owner and a saving-time site-match
        // invariant. Canonical backlinks point into another page's metadata;
        // they have no matching inverse owner and keep their independent site.
        if ($relation['ownerRelation'] !== null && self::relation($target, $relation['ownerRelation']) !== null) {
            return true;
        }

        foreach (self::modelRelations()[$target] ?? [] as $owner) {
            if ($relation['childKey'] !== null && $owner['foreignKey'] === $relation['childKey']
                && $owner['target'] === $class
                && preg_match('/@property\s+([^\s]+)\s+\$' . preg_quote($relation['childKey'], '/') . '\b/', $doc, $match) === 1
                && ! str_contains($match[1], 'null') && ! str_contains($match[1], '?')) {
                return true;
            }
        }

        return false;
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
                $ownedClass = 'Capell\\Core\\Models\\' . $owned;
                if (is_a($class, $ownedClass, true) || $class !== Model::class && get_parent_class($ownedClass) === $class) {
                    return $owned;
                }
            }

            $fillable = new ReflectionClass($class)->getDefaultProperties()['fillable'] ?? [];
            if (is_array($fillable) && array_any($fillable, static fn (mixed $attribute): bool => is_string($attribute) && preg_match('/(?:^|_)site_id$/', $attribute) === 1)) {
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

        foreach (array_keys(self::modelRelations()) as $class) {
            if (self::siteModel($class) !== null && $table === (new $class)->getTable()) {
                return true;
            }
        }

        return in_array($table, ['activity_log', 'page_term'], true);
    }

    private static function unknownModelClass(?string $class): bool
    {
        // An unresolved class, base-model or interface does not establish ownership.
        return $class === null || ! class_exists($class)
            || is_a($class, Builder::class, true) || is_a($class, Relation::class, true)
            || is_a($class, Model::class, true) && new ReflectionClass($class)->isAbstract();
    }

    /** @param array<Node> $nodes */
    private static function className(Node $expression, Node $at, NodeFinder $finder, array $nodes): ?string
    {
        if ($expression instanceof Name) {
            if (in_array($expression->toString(), ['self', 'static'], true)) {
                $class = self::ancestor($at, Class_::class);

                return $class instanceof Class_ ? ($class->namespacedName?->toString() ?? $class->name?->toString()) : null;
            }

            return $expression->toString() === 'parent' ? null : $expression->toString();
        }

        if ($expression instanceof String_) {
            return ltrim($expression->value, '\\');
        }

        if ($expression instanceof ClassConstFetch || $expression instanceof New_) {
            return self::className($expression->class, $at, $finder, $nodes);
        }

        if ($expression instanceof StaticCall || $expression instanceof MethodCall || $expression instanceof NullsafeMethodCall) {
            $class = self::className($expression instanceof StaticCall ? $expression->class : $expression->var, $at, $finder, $nodes);
            if ($expression->name instanceof Identifier) {
                $method = $expression->name->toString();
                if ($class === SiteAccess::class && $method === 'query' && isset($expression->args[0]) && $expression->args[0] instanceof Arg) {
                    return self::className($expression->args[0]->value, $at, $finder, $nodes);
                }

                if ($class !== null && is_a($class, Model::class, true)) {
                    $target = self::relationTarget($class, $method);
                    if ($target !== null) {
                        return $target;
                    }

                    if (self::queryMethod($method) || ! method_exists($class, $method)
                        || in_array($method, ['fresh', 'refresh', 'replicate', 'duplicate'], true)) {
                        return $class;
                    }
                }
            }

            if ($class !== null && $expression->name instanceof Identifier && method_exists($class, $expression->name->toString())) {
                $type = new ReflectionMethod($class, $expression->name->toString())->getReturnType();

                return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
                    ? (in_array($type->getName(), ['self', 'static'], true) ? $class : $type->getName()) : null;
            }
        }

        if (($expression instanceof PropertyFetch || $expression instanceof NullsafePropertyFetch) && $expression->name instanceof Identifier) {
            $receiver = self::className($expression->var, $at, $finder, $nodes);
            $target = self::relationTarget($receiver, $expression->name->toString());
            if ($target !== null) {
                return $target;
            }

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
                        && $parameter->var->name === $expression->name->toString()) {
                        $type = $parameter->type instanceof NullableType ? $parameter->type->type : $parameter->type;
                        if ($type instanceof Name) {
                            return $type->toString();
                        }
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
            $declared = false;
            if ($scope !== null) {
                foreach ($scope->getParams() as $param) {
                    $type = $param->type instanceof NullableType ? $param->type->type : $param->type;
                    if ($param->var instanceof Variable && $param->var->name === $expression->name) {
                        $declared = true;
                        $bound = $type instanceof Name ? $type->toString() : null;
                    }
                }
            }

            if (! $declared && $scope instanceof ArrowFunction) {
                $bound = self::className($expression, $scope, $finder, $nodes);
            } elseif (! $declared && $scope instanceof Closure) {
                foreach ($scope->uses as $use) {
                    if ($use->var->name === $expression->name && ! $use->byRef) {
                        $bound = self::className($expression, $scope, $finder, $nodes);
                    }
                }
            }

            foreach ($finder->findInstanceOf($scope?->getStmts() ?? $nodes, Assign::class) as $assignment) {
                if (self::lexicalScope($assignment) === $scope && $assignment->var instanceof Variable && $assignment->var->name === $expression->name
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
    private static function nonRelationHelper(MethodCall|NullsafeMethodCall $call, NodeFinder $finder, array $nodes): bool
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
        return ($node instanceof StaticCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Identifier && in_array($node->name->toString(), self::EXECUTES_QUERY, true);
    }

    private static function accessVariable(Variable $variable, Node $at): bool
    {
        return self::className($variable, $at, new NodeFinder, []) === SiteAccess::class;
    }

    private static function localHelper(MethodCall|NullsafeMethodCall $call): bool
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
