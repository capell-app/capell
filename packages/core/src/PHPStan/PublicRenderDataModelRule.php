<?php

declare(strict_types=1);

namespace Capell\Core\PHPStan;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\PublicRenderData;
use Illuminate\Database\Eloquent\Model;
use Override;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Forbids Eloquent models and Pageable instances in classes that implement
 * {@see PublicRenderData}, including properties promoted from the constructor.
 *
 * @implements Rule<InClassNode>
 */
final class PublicRenderDataModelRule implements Rule
{
    #[Override]
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /** @return list<IdentifierRuleError> */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! $class->implementsInterface(PublicRenderData::class)) {
            return [];
        }

        $errors = [];

        foreach ($class->getNativeReflection()->getProperties() as $nativeProperty) {
            if ($nativeProperty->isStatic()) {
                continue;
            }

            if ($nativeProperty->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $type = $class->getNativeProperty($nativeProperty->getName())->getReadableType();
            $offending = $this->offendingClass($type);

            if ($offending === null) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Public render data %s::$%s holds %s. Public render data must contain hydrated Data only, never an Eloquent model or Pageable.',
                $class->getName(),
                $nativeProperty->getName(),
                $offending,
            ))
                ->identifier('capell.publicRenderDataModel')
                ->line($node->getOriginalNode()->getStartLine())
                ->build();
        }

        return $errors;
    }

    private function offendingClass(Type $type): ?string
    {
        foreach ($type->getReferencedClasses() as $className) {
            $object = new ObjectType($className);

            if ($object->isInstanceOf(Model::class)->yes() || $object->isInstanceOf(Pageable::class)->yes()) {
                return $className;
            }
        }

        return null;
    }
}
