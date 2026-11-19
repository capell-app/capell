<?php

declare(strict_types=1);

namespace Capell\Core\PHPStan;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Override;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Reports code that expects a number read from the cache to come back as an
 * int or float.
 *
 * The Redis cache store (which Valkey uses) keeps numbers unserialised and
 * returns them as numeric strings, so `is_int(Cache::get($key))` is always
 * false there and an `int` return of `Cache::remember(...)` throws a TypeError
 * once the value is cached. The array store used in tests returns the int, so
 * only production sees it. Normalise the value first, for example with
 * `is_numeric($value) ? (int) $value : $default`.
 *
 * A variable counts as a cache read when its latest assignment that completes
 * before the check, in source order and in the same function, is a cache read.
 * Re-assigning it from a cast first satisfies the rule; a closure sees the
 * enclosing function's variables only through `use` or as an arrow function.
 *
 * @implements Rule<FunctionLike>
 */
final class CacheNumericReadRule implements Rule
{
    /** Repository and store methods that return a stored value. */
    private const array READ_METHODS = ['get', 'pull', 'remember', 'rememberforever', 'sear', 'flexible'];

    /** Methods that select a store and return a repository. */
    private const array STORE_METHODS = ['store', 'driver'];

    /** @var array<string, string> Type check function => the type it expects. */
    private const array TYPE_CHECKS = ['is_int' => 'int', 'is_integer' => 'int', 'is_long' => 'int', 'is_float' => 'float', 'is_double' => 'float'];

    #[Override]
    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /** @return list<IdentifierRuleError> */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        // A closure inside a method or function is checked with the method or
        // function that contains it, which knows the variables it reads.
        if (($node instanceof Closure || $node instanceof ArrowFunction) && ($scope->isInAnonymousFunction() || is_string($scope->getFunctionName()))) {
            return [];
        }

        $context = ['found' => [], 'writes' => [], 'parents' => [spl_object_id($node) => null]];
        $this->collect($node, $context);
        $errors = [];
        $seen = [];

        foreach ($context['found'] as [$candidate, $owner]) {
            // An arrow function body that is itself a call is collected twice.
            if (isset($seen[spl_object_id($candidate)])) {
                continue;
            }

            $seen[spl_object_id($candidate)] = true;
            $returned = match (true) {
                $candidate instanceof Return_ => $candidate->expr,
                $candidate instanceof Expr && $owner instanceof ArrowFunction && $owner->expr === $candidate => $candidate,
                default => null,
            };
            $number = $returned instanceof Expr ? $this->returnsNumber($owner->getReturnType()) : null;

            if ($number !== null && $returned instanceof Expr && $this->holdsCacheRead($returned, $owner, $scope, $context)) {
                $errors[] = $this->error(sprintf('Returning a value read from the cache as %s throws a TypeError on the Redis store, which returns stored numbers as numeric strings.', $number), $candidate);
            }

            if (! $candidate instanceof FuncCall) {
                continue;
            }

            if (! $candidate->name instanceof Name) {
                continue;
            }

            if ($candidate->isFirstClassCallable()) {
                continue;
            }

            $check = $candidate->name->toLowerString();
            $argument = $candidate->getArgs()[0]->value ?? null;

            if (isset(self::TYPE_CHECKS[$check]) && $argument instanceof Expr && $this->holdsCacheRead($argument, $owner, $scope, $context)) {
                $errors[] = $this->error(sprintf('%s() on a value read from the cache is false on the Redis store, which returns stored numbers as numeric strings.', $check), $candidate);
            }
        }

        return $errors;
    }

    private function error(string $message, Node $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('capell.cacheNumericRead')
            ->line($node->getStartLine())
            ->tip('Normalise the value first, for example is_numeric($value) ? (int) $value : $default.')
            ->build();
    }

    /**
     * Collects, in source order, the calls and returns of a function and the
     * closures inside it, each with the function it belongs to, and every write
     * to a variable. Named functions and classes declared inside are reported on
     * their own.
     *
     * @param  array{found: list<array{0: Node, 1: FunctionLike}>, writes: list<array{name: string, end: int, expr: ?Expr, owner: FunctionLike}>, parents: array<int, ?FunctionLike>}  $context
     */
    private function collect(FunctionLike $function, array &$context): void
    {
        if ($function instanceof ArrowFunction) {
            // The body of an arrow function is its return value.
            $context['found'][] = [$function->expr, $function];
            $this->walk([$function->expr], $function, $context);

            return;
        }

        $this->walk($function->getStmts() ?? [], $function, $context);
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @param  array{found: list<array{0: Node, 1: FunctionLike}>, writes: list<array{name: string, end: int, expr: ?Expr, owner: FunctionLike}>, parents: array<int, ?FunctionLike>}  $context
     */
    private function walk(array $nodes, FunctionLike $owner, array &$context): void
    {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->walk($node, $owner, $context);

                continue;
            }

            if (! $node instanceof Node) {
                continue;
            }

            if ($node instanceof Function_) {
                continue;
            }

            if ($node instanceof ClassLike) {
                continue;
            }

            if ($node instanceof Closure || $node instanceof ArrowFunction) {
                $context['parents'][spl_object_id($node)] = $owner;
                $this->collect($node, $context);

                continue;
            }

            if ($node instanceof FuncCall || $node instanceof Return_) {
                $context['found'][] = [$node, $owner];
            }

            $this->recordWrites($node, $owner, $context);

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                $this->walk(is_array($child) ? $child : [$child], $owner, $context);
            }
        }
    }

    /**
     * Records the variables a node writes. Only a plain assignment keeps the
     * value it assigns; every other write replaces whatever was read before.
     *
     * @param  array{found: list<array{0: Node, 1: FunctionLike}>, writes: list<array{name: string, end: int, expr: ?Expr, owner: FunctionLike}>, parents: array<int, ?FunctionLike>}  $context
     */
    private function recordWrites(Node $node, FunctionLike $owner, array &$context): void
    {
        $targets = match (true) {
            $node instanceof Assign => [[$node->var, $node->expr, $node->getEndFilePos()]],
            $node instanceof AssignRef, $node instanceof AssignOp => [[$node->var, null, $node->getEndFilePos()]],
            $node instanceof Foreach_ => [[$node->keyVar, null, $node->expr->getEndFilePos()], [$node->valueVar, null, $node->expr->getEndFilePos()]],
            $node instanceof Catch_ => [[$node->var, null, $node->getStartFilePos()]],
            default => [],
        };

        foreach ($targets as [$target, $expr, $end]) {
            foreach ($this->writtenVariables($target) as $variable) {
                $context['writes'][] = ['name' => $variable, 'end' => $end, 'expr' => $target instanceof Variable ? $expr : null, 'owner' => $owner];
            }
        }
    }

    /** @return list<string> */
    private function writtenVariables(mixed $target): array
    {
        if ($target instanceof Variable) {
            return is_string($target->name) ? [$target->name] : [];
        }

        if (! $target instanceof List_ && ! $target instanceof Array_) {
            return [];
        }

        $names = [];

        foreach ($target->items as $item) {
            if ($item !== null) {
                $names = [...$names, ...$this->writtenVariables($item->value)];
            }
        }

        return $names;
    }

    /** The numeric type a native return type declares, when it cannot also hold a string. */
    private function returnsNumber(?Node $type): ?string
    {
        $types = match (true) {
            $type instanceof Identifier => [$type],
            $type instanceof NullableType => [$type->type],
            $type instanceof UnionType => $type->types,
            default => [],
        };
        $names = array_map(static fn (Node $type): string => $type instanceof Identifier ? $type->toLowerString() : '', $types);

        if (array_intersect($names, ['string', 'mixed']) !== []) {
            return null;
        }

        foreach (['int', 'float'] as $number) {
            if (in_array($number, $names, true)) {
                return $number;
            }
        }

        return null;
    }

    /** @param  array{found: list<array{0: Node, 1: FunctionLike}>, writes: list<array{name: string, end: int, expr: ?Expr, owner: FunctionLike}>, parents: array<int, ?FunctionLike>}  $context */
    private function holdsCacheRead(Expr $expr, FunctionLike $owner, Scope $scope, array $context): bool
    {
        if ($expr instanceof Variable) {
            $origin = $this->origin($expr, $owner, $context);

            return $origin !== null && $origin['expr'] instanceof Expr && $this->isCacheRead($origin['expr'], $origin['owner'], $scope, $context);
        }

        return $this->isCacheRead($expr, $owner, $scope, $context);
    }

    /** @param  array{found: list<array{0: Node, 1: FunctionLike}>, writes: list<array{name: string, end: int, expr: ?Expr, owner: FunctionLike}>, parents: array<int, ?FunctionLike>}  $context */
    private function isCacheRead(Expr $expr, FunctionLike $owner, Scope $scope, array $context): bool
    {
        if ($expr instanceof Coalesce) {
            return $this->isCacheRead($expr->left, $owner, $scope, $context);
        }

        if ($expr instanceof FuncCall) {
            $argument = $expr->getArgs()[0]->value ?? null;

            // cache('key') reads; cache(['key' => $value]) writes.
            return $this->isCacheHelper($expr) && $argument instanceof Expr && ! $argument instanceof Array_;
        }

        if (! $this->calls($expr, self::READ_METHODS)) {
            return false;
        }

        if ($expr instanceof StaticCall) {
            return $this->isCacheFacade($expr, $scope);
        }

        /** @var MethodCall|NullsafeMethodCall $expr */
        return $this->isCacheRepository($expr->var, $owner, $scope, $context);
    }

    /** @param  array{found: list<array{0: Node, 1: FunctionLike}>, writes: list<array{name: string, end: int, expr: ?Expr, owner: FunctionLike}>, parents: array<int, ?FunctionLike>}  $context */
    private function isCacheRepository(Expr $expr, FunctionLike $owner, Scope $scope, array $context): bool
    {
        if ($expr instanceof Variable && is_string($expr->name) && $expr->name !== 'this') {
            $origin = $this->origin($expr, $owner, $context);

            if ($origin === null) {
                return false;
            }

            if ($origin['expr'] instanceof Param) {
                return $origin['expr']->type instanceof Node && $this->isCacheType($scope->getFunctionType($origin['expr']->type, false, false));
            }

            return $origin['expr'] instanceof Expr && $this->isCacheRepository($origin['expr'], $origin['owner'], $scope, $context);
        }

        if ($this->isCacheHelper($expr) && $expr instanceof FuncCall && $expr->getArgs() === []) {
            return true;
        }

        if ($this->calls($expr, self::STORE_METHODS)) {
            /** @var MethodCall|NullsafeMethodCall|StaticCall $expr */
            $store = $expr->getArgs()[0]->value ?? null;

            // The array store keeps values as PHP values, so numbers stay numbers.
            if ($store instanceof String_ && $store->value === 'array') {
                return false;
            }

            if ($expr instanceof StaticCall) {
                return $this->isCacheFacade($expr, $scope);
            }

            if ($this->isCacheRepository($expr->var, $owner, $scope, $context)) {
                return true;
            }
        }

        // A method is analysed from its class scope, where $this is not a variable.
        if ($expr instanceof PropertyFetch && $expr->var instanceof Variable && $expr->var->name === 'this' && $expr->name instanceof Identifier) {
            $class = $scope->isInClass() ? $scope->getClassReflection() : null;

            return $class instanceof ClassReflection && $class->hasNativeProperty($expr->name->name) && $this->isCacheType($class->getNativeProperty($expr->name->name)->getReadableType());
        }

        return $this->isCacheType($scope->getType($expr));
    }

    private function isCacheType(Type $type): bool
    {
        return array_any([Repository::class, Store::class, CacheManager::class], fn (string $class): bool => new ObjectType($class)->isSuperTypeOf($type)->yes());
    }

    /** @param  list<string>  $methods */
    private function calls(Expr $expr, array $methods): bool
    {
        return ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall)
            && $expr->name instanceof Identifier
            && ! $expr->isFirstClassCallable()
            && in_array($expr->name->toLowerString(), $methods, true);
    }

    private function isCacheFacade(StaticCall $call, Scope $scope): bool
    {
        return $call->class instanceof Name && ltrim($scope->resolveName($call->class), '\\') === Cache::class;
    }

    private function isCacheHelper(Expr $expr): bool
    {
        return $expr instanceof FuncCall && $expr->name instanceof Name && ! $expr->isFirstClassCallable() && $expr->name->toLowerString() === 'cache';
    }

    /**
     * Where a variable's value at `$variable` comes from: the latest write in the
     * same function that completes before it, the function's parameter, or the
     * enclosing function's value for an arrow function or a closure's `use`.
     *
     * @param  array{found: list<array{0: Node, 1: FunctionLike}>, writes: list<array{name: string, end: int, expr: ?Expr, owner: FunctionLike}>, parents: array<int, ?FunctionLike>}  $context
     * @return array{expr: Expr|Param|null, owner: FunctionLike}|null
     */
    private function origin(Variable $variable, FunctionLike $owner, array $context, ?int $position = null): ?array
    {
        if (! is_string($variable->name)) {
            return null;
        }

        $position ??= $variable->getStartFilePos();
        $latest = null;

        foreach ($context['writes'] as $write) {
            if ($write['owner'] === $owner && $write['name'] === $variable->name && $write['end'] < $position) {
                $latest = $write;
            }
        }

        if ($latest !== null) {
            return ['expr' => $latest['expr'], 'owner' => $owner];
        }

        foreach ($owner->getParams() as $parameter) {
            if ($parameter->var instanceof Variable && $parameter->var->name === $variable->name) {
                return ['expr' => $parameter, 'owner' => $owner];
            }
        }

        $parent = $context['parents'][spl_object_id($owner)] ?? null;
        $inherits = $owner instanceof ArrowFunction
            || ($owner instanceof Closure && array_any($owner->uses, static fn (ClosureUse $use): bool => $use->var->name === $variable->name));

        return $parent !== null && $inherits ? $this->origin($variable, $parent, $context, $owner->getStartFilePos()) : null;
    }
}
