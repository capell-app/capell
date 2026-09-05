<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Exceptions\PropertyValueValidationException;
use Capell\Core\Models\Page;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Validates one {@see PropertyValueData} against its resolved effective
 * definition: type match, currency/unit rules, localisation, and blueprint
 * attachment. Returns the matched definition so the caller does not have to
 * re-resolve it.
 */
final class PropertyValueValidator
{
    /**
     * @param  Collection<int, EffectivePropertyDefinitionData>  $effectiveDefinitions
     */
    public function validate(Page $page, PropertyValueData $value, Collection $effectiveDefinitions): EffectivePropertyDefinitionData
    {
        $definition = $effectiveDefinitions->first(
            static fn (EffectivePropertyDefinitionData $candidate): bool => $candidate->key === $value->propertyKey,
        );

        if (! $definition instanceof EffectivePropertyDefinitionData) {
            throw PropertyValueValidationException::notAttachedToBlueprint($value->propertyKey);
        }

        $this->assertType($definition, $value);

        if ($definition->type->requiresCurrency() && $value->value !== null && $value->currency === null) {
            throw PropertyValueValidationException::currencyRequired($value->propertyKey);
        }

        if ($definition->type->requiresUnit() && $value->unit !== null) {
            $allowed = $definition->unitConfig['allowed'] ?? null;

            if (is_array($allowed) && ! in_array($value->unit, $allowed, true)) {
                throw PropertyValueValidationException::unitNotAllowed($value->propertyKey, $value->unit);
            }
        }

        if ($definition->localised) {
            if ($value->translationId === null) {
                throw PropertyValueValidationException::localisedTranslationRequired($value->propertyKey);
            }

            $belongsToPage = $page->translations()->whereKey($value->translationId)->exists();

            if (! $belongsToPage) {
                throw PropertyValueValidationException::localisedTranslationRequired($value->propertyKey);
            }
        }

        return $definition;
    }

    private function assertType(EffectivePropertyDefinitionData $definition, PropertyValueData $value): void
    {
        if ($value->value === null) {
            return;
        }

        if ($value->type !== $definition->type) {
            throw PropertyValueValidationException::typeMismatch($value->propertyKey, $definition->type->value);
        }

        $shapeMatches = match (true) {
            $definition->type->isNumeric() => is_numeric($value->value),
            $definition->type->isBoolean() => is_bool($value->value),
            $definition->type->isTemporal() => $value->value instanceof DateTimeInterface || is_string($value->value),
            default => is_scalar($value->value),
        };

        if (! $shapeMatches) {
            throw PropertyValueValidationException::typeMismatch($value->propertyKey, $definition->type->value);
        }
    }
}
