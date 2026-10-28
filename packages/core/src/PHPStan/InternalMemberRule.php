<?php

declare(strict_types=1);

namespace Capell\Core\PHPStan;

use Override;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\VarLikeIdentifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Opt-in rule reporting use of `@internal` methods, properties and constants
 * declared on Capell classes.
 *
 * @internal
 *
 * @implements Rule<Expr>
 */
final class InternalMemberRule implements Rule
{
    #[Override]
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /** @return list<IdentifierRuleError> */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        [$kind, $classType, $name] = $this->describe($node, $scope);

        if ($kind === null || $classType === null || $name === null) {
            return [];
        }

        [$declaring, $comment] = $this->lookup($kind, $classType, $name, $scope);

        if ($declaring === null || ! str_starts_with($declaring, 'Capell\\')) {
            return [];
        }

        if ($comment === null || preg_match('/@internal\b/', $comment) !== 1) {
            return [];
        }

        if ($scope->isInClass() && $scope->getClassReflection()->getName() === $declaring) {
            return [];
        }

        return [RuleErrorBuilder::message(sprintf('Capell %s %s::%s is @internal and not a stable extension API.', $kind, $declaring, $name))
            ->identifier('capell.internalMember')
            ->build()];
    }

    /** @return array{0: string|null, 1: Type|null, 2: string|null} */
    private function describe(Node $node, Scope $scope): array
    {
        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            return ['method', $scope->getType($node->var), $node->name instanceof Identifier ? $node->name->name : null];
        }

        if ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            return ['property', $scope->getType($node->var), $node->name instanceof Identifier ? $node->name->name : null];
        }

        if ($node instanceof StaticCall && $node->class instanceof Name) {
            return ['method', $scope->resolveTypeByName($node->class), $node->name instanceof Identifier ? $node->name->name : null];
        }

        if ($node instanceof StaticPropertyFetch && $node->class instanceof Name) {
            return ['static property', $scope->resolveTypeByName($node->class), $node->name instanceof VarLikeIdentifier ? $node->name->name : null];
        }

        if ($node instanceof ClassConstFetch && $node->class instanceof Name) {
            return ['constant', $scope->resolveTypeByName($node->class), $node->name instanceof Identifier ? $node->name->name : null];
        }

        return [null, null, null];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function lookup(string $kind, Type $classType, string $name, Scope $scope): array
    {
        if ($kind === 'method') {
            if (! $classType->hasMethod($name)->yes()) {
                return [null, null];
            }

            $method = $classType->getMethod($name, $scope);

            return [$method->getDeclaringClass()->getName(), $method->getDocComment()];
        }

        if ($kind === 'constant') {
            if (! $classType->hasConstant($name)->yes()) {
                return [null, null];
            }

            $constant = $classType->getConstant($name);

            return [$constant->getDeclaringClass()->getName(), $constant->getDocComment()];
        }

        if ($kind === 'static property') {
            if (! $classType->hasStaticProperty($name)->yes()) {
                return [null, null];
            }

            $property = $classType->getStaticProperty($name, $scope);

            return [$property->getDeclaringClass()->getName(), $property->getDocComment()];
        }

        if (! $classType->hasInstanceProperty($name)->yes()) {
            return [null, null];
        }

        $property = $classType->getInstanceProperty($name, $scope);

        return [$property->getDeclaringClass()->getName(), $property->getDocComment()];
    }
}
