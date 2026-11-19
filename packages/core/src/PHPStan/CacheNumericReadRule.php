<?php

declare(strict_types=1);

namespace Capell\Core\PHPStan;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
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
use PhpParser\Node\Stmt\ClassLike;
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
 * A variable counts as a cache read when its latest assignment before the
 * check, in source order, is a cache read; re-assigning it from a cast first
 * satisfies the rule.
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

        /** @var list<array{0: Node, 1: FunctionLike}> $found */
        $found = [];
        $this->collect($node, $found);

        $assignments = [];
        $parameters = [];

        foreach ($found as [$candidate, $owner]) {
            if ($candidate instanceof Assign && $candidate->var instanceof Variable && is_string($candidate->var->name)) {
                $assignments[] = $candidate;
            }

            foreach ($owner->getParams() as $parameter) {
                if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                    $parameters[$parameter->var->name] = $parameter;
                }
            }
        }

        foreach ($node->getParams() as $parameter) {
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $parameters[$parameter->var->name] = $parameter;
            }
        }

        $context = ['assignments' => $assignments, 'parameters' => $parameters];
        $errors = [];

        foreach ($found as [$candidate, $owner]) {
            if ($candidate instanceof FuncCall && $candidate->name instanceof Name && ! $candidate->isFirstClassCallable()) {
                $check = $candidate->name->toLowerString();
                $argument = $candidate->getArgs()[0]->value ?? null;

                if (isset(self::TYPE_CHECKS[$check]) && $argument instanceof Expr && $this->holdsCacheRead($argument, $scope, $context)) {
                    $errors[] = $this->error(sprintf('%s() on a value read from the cache is false on the Redis store, which returns stored numbers as numeric strings.', $check), $candidate);
                }

                continue;
            }

            $returned = match (true) {
                $candidate instanceof Return_ => $candidate->expr,
                $candidate instanceof Expr && $owner instanceof ArrowFunction && $owner->expr === $candidate => $candidate,
                default => null,
            };
            $number = $returned instanceof Expr ? $this->returnsNumber($owner->getReturnType()) : null;

            if ($number !== null && $returned instanceof Expr && $this->holdsCacheRead($returned, $scope, $context)) {
                $errors[] = $this->error(sprintf('Returning a value read from the cache as %s throws a TypeError on the Redis store, which returns stored numbers as numeric strings.', $number), $candidate);
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
     * Collects, in source order, the calls, returns and assignments of a
     * function and the closures inside it, each with the function it returns
     * from. Named functions and classes declared inside are reported on their own.
     *
     * @param  list<array{0: Node, 1: FunctionLike}>  $found
     */
    private function collect(FunctionLike $function, array &$found): void
    {
        if ($function instanceof ArrowFunction) {
            // The body of an arrow function is its return value.
            $found[] = [$function->expr, $function];
            $this->walk(array_map(static fn (string $name): mixed => $function->expr->{$name}, $function->expr->getSubNodeNames()), $function, $found);

            return;
        }

        $this->walk($function->getStmts() ?? [], $function, $found);
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @param  list<array{0: Node, 1: FunctionLike}>  $found
     */
    private function walk(array $nodes, FunctionLike $owner, array &$found): void
    {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->walk($node, $owner, $found);

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
                $this->collect($node, $found);

                continue;
            }

            if ($node instanceof FuncCall || $node instanceof Return_ || $node instanceof Assign) {
                $found[] = [$node, $owner];
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                $this->walk(is_array($child) ? $child : [$child], $owner, $found);
            }
        }
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

    /** @param  array{assignments: list<Assign>, parameters: array<string, Param>}  $context */
    private function holdsCacheRead(Expr $expr, Scope $scope, array $context): bool
    {
        if ($expr instanceof Variable) {
            $assignment = $this->latestAssignment($expr, $context['assignments']);

            return $assignment instanceof Assign && $this->isCacheRead($assignment->expr, $scope, $context);
        }

        return $this->isCacheRead($expr, $scope, $context);
    }

    /** @param  array{assignments: list<Assign>, parameters: array<string, Param>}  $context */
    private function isCacheRead(Expr $expr, Scope $scope, array $context): bool
    {
        if ($expr instanceof Coalesce) {
            return $this->isCacheRead($expr->left, $scope, $context);
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
        return $this->isCacheRepository($expr->var, $scope, $context, $expr);
    }

    /** @param  array{assignments: list<Assign>, parameters: array<string, Param>}  $context */
    private function isCacheRepository(Expr $expr, Scope $scope, array $context, Node $at): bool
    {
        if ($expr instanceof Variable && is_string($expr->name) && $expr->name !== 'this') {
            $assignment = $this->latestAssignment($expr, $context['assignments'], $at);

            if ($assignment instanceof Assign) {
                return $this->isCacheRepository($assignment->expr, $scope, $context, $assignment);
            }

            $parameter = $context['parameters'][$expr->name] ?? null;

            return $parameter instanceof Param && $parameter->type instanceof Node && $this->isCacheType($scope->getFunctionType($parameter->type, false, false));
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

            if ($this->isCacheRepository($expr->var, $scope, $context, $expr)) {
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
     * The last assignment to the variable that starts before `$before`.
     *
     * @param  list<Assign>  $assignments
     */
    private function latestAssignment(Variable $variable, array $assignments, ?Node $before = null): ?Assign
    {
        $position = ($before ?? $variable)->getStartFilePos();
        $latest = null;

        foreach ($assignments as $assignment) {
            if (! $assignment->var instanceof Variable) {
                continue;
            }

            if ($assignment->var->name !== $variable->name) {
                continue;
            }

            if ($assignment->getStartFilePos() < $position) {
                $latest = $assignment;
            }
        }

        return $latest;
    }
}
