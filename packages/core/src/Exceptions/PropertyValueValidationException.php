<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Support\Properties\PropertyValueValidator;
use RuntimeException;

/**
 * Thrown by {@see PropertyValueValidator} when a property value fails a
 * type, unit, currency, localisation, or blueprint-attachment rule, or by
 * {@see SetPagePropertyValuesAction} when a
 * caller attempts to write a promoted property directly. A locked
 * definition's requirement/agent-visibility floor is never violated via an
 * exception — {@see ResolveEffectiveDefinitionsAction}
 * clamps silently, so there is no corresponding throw here.
 */
final class PropertyValueValidationException extends RuntimeException
{
    public static function typeMismatch(string $property, string $type): self
    {
        return new self(__('capell-core::properties.validation.type_mismatch', [
            'property' => $property,
            'type' => $type,
        ]));
    }

    public static function currencyRequired(string $property): self
    {
        return new self(__('capell-core::properties.validation.currency_required', [
            'property' => $property,
        ]));
    }

    public static function unitNotAllowed(string $property, string $unit): self
    {
        return new self(__('capell-core::properties.validation.unit_not_allowed', [
            'property' => $property,
            'unit' => $unit,
        ]));
    }

    public static function localisedTranslationRequired(string $property): self
    {
        return new self(__('capell-core::properties.validation.localised_translation_required', [
            'property' => $property,
        ]));
    }

    public static function notAttachedToBlueprint(string $property): self
    {
        return new self(__('capell-core::properties.validation.not_attached_to_blueprint', [
            'property' => $property,
        ]));
    }

    public static function promotedPropertyDirectWrite(string $property, string $field): self
    {
        return new self(__('capell-core::properties.validation.promoted_property_direct_write', [
            'property' => $property,
            'field' => $field,
        ]));
    }
}
