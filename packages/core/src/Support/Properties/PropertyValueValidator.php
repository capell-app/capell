<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Exceptions\PropertyValueValidationException;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Term;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\MediaModel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        $this->assertReference($page, $definition->type, $value);

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
        if ($value->type !== $definition->type) {
            throw PropertyValueValidationException::typeMismatch($value->propertyKey, $definition->type->value);
        }

        if ($value->value === null) {
            return;
        }

        $shapeMatches = match (true) {
            $definition->type->isNumeric() => is_numeric($value->value),
            $definition->type->isBoolean() => is_bool($value->value),
            $definition->type->isTemporal() => $value->value instanceof DateTimeInterface || is_string($value->value),
            $definition->type->isReference() => true,
            default => is_scalar($value->value),
        };

        if (! $shapeMatches) {
            throw PropertyValueValidationException::typeMismatch($value->propertyKey, $definition->type->value);
        }
    }

    private function assertReference(Page $page, PropertyType $type, PropertyValueData $value): void
    {
        if (! $type->isReference() || $value->value === null) {
            return;
        }

        if (! $this->isPositiveInteger($value->value)) {
            throw PropertyValueValidationException::referenceIdRequired($value->propertyKey);
        }

        $id = (int) $value->value;
        $belongsToSite = match ($type) {
            PropertyType::EntryReference => Page::query()->whereKey($id)->where('site_id', $page->site_id)->exists(),
            PropertyType::TermReference => Term::query()->whereKey($id)->whereHas('taxonomy', static fn (Builder $query): Builder => $query->where('site_id', $page->site_id))->exists(),
            PropertyType::Media => $this->mediaBelongsToSite($id, $page->site_id),
            default => true,
        };

        if (! $belongsToSite) {
            throw PropertyValueValidationException::referenceOutsideSite($value->propertyKey);
        }
    }

    private function mediaBelongsToSite(int $mediaId, int $siteId): bool
    {
        $media = MediaModel::query()->whereKey($mediaId)->whereNull('deleted_at')->first();
        if (! $media instanceof Model) {
            return false;
        }

        if (! method_exists($media, 'model')) {
            return false;
        }

        $owner = $media->getRelationValue('model');
        if (! $owner instanceof Model) {
            return false;
        }

        if ($owner instanceof Site) {
            return $owner->id === $siteId;
        }

        $ownerSiteId = $owner->getAttribute('site_id');
        if (is_numeric($ownerSiteId)) {
            return (int) $ownerSiteId === $siteId;
        }

        if (! $owner instanceof Translation) {
            return false;
        }

        $translatable = $owner->getRelationValue('translatable');

        return $translatable instanceof Model
            && (int) $translatable->getAttribute('site_id') === $siteId;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0
            || is_string($value) && preg_match('/\A[1-9]\d*\z/', $value) === 1;
    }
}
