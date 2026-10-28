<?php

declare(strict_types=1);

namespace Capell\Core\PHPStan;

use Override;
use PhpParser\Node;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Opt-in rule for code outside the Capell foundation.
 *
 * @internal
 *
 * @implements Rule<Name>
 */
final class InternalClassRule implements Rule
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    #[Override]
    public function getNodeType(): string
    {
        return Name::class;
    }

    /** @return list<IdentifierRuleError> */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $scope->resolveName($node);

        if (! str_starts_with($name, 'Capell\\') || ! $this->reflectionProvider->hasClass($name)) {
            return [];
        }

        $class = $this->reflectionProvider->getClass($name);
        $comment = $class->getNativeReflection()->getDocComment();

        if ($comment === false || preg_match('/@internal\b/', $comment) !== 1) {
            return [];
        }

        return [RuleErrorBuilder::message(sprintf('Capell class %s is not a stable extension API; consult the extension surface catalogue.', $class->getName()))
            ->identifier('capell.internalClass')
            ->build()];
    }
}
