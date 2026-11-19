<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Exceptions\PropertyValueValidationException;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Support\Properties\PropertyValueEquality;
use Capell\Core\Support\Properties\PropertyValueValidator;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Writes a SiteSpec page's `properties` through {@see SetPagePropertyValuesAction},
 * so an imported value meets exactly the same type and constraint checks as
 * one saved in the admin or through the agent API. Every failure is reported
 * as a validation error keyed `pages.<slug>.properties.<key>`, and the caller's
 * import transaction rolls back.
 *
 * A SiteSpec carries one language, so a localised value is written to the
 * page's translation in that language (the site's default language when none
 * is given).
 *
 * @method static void run(Page $page, string $slug, array<string, mixed> $properties, ?int $languageId = null)
 */
final class ApplySiteSpecPagePropertiesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function handle(Page $page, string $slug, array $properties, ?int $languageId = null): void
    {
        if ($properties === []) {
            return;
        }

        [$values, $errorKeys] = $this->values($page, $slug, $properties, $languageId);

        // One write for the whole page, so the import records a single page
        // version rather than one per property.
        try {
            SetPagePropertyValuesAction::run($page, array_values(array_map(static fn (array $value): PropertyValueData => $value['data'], $values)));
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $propertyKey => $messages) {
                $errors[$errorKeys[$propertyKey] ?? sprintf('pages.%s.properties', $slug)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        } catch (PropertyValueValidationException $exception) {
            throw ValidationException::withMessages([sprintf('pages.%s.properties', $slug) => $exception->getMessage()]);
        }
    }

    /**
     * The spec's property keys whose value differs from what the page stores, so a
     * plan can report an update without writing. A key the page type does not carry
     * is an error, exactly as it is when applying.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, array{before: ?string, after: ?string}> Keyed by the spec's property key.
     */
    public function differences(Page $page, string $slug, array $properties, ?int $languageId = null): array
    {
        if ($properties === []) {
            return [];
        }

        [$values, $errorKeys] = $this->values($page, $slug, $properties, $languageId);
        $changed = [];
        $definitions = ResolveEffectiveDefinitionsAction::run($page);

        // Planning refuses what applying refuses, so a plan that succeeds can be applied.
        foreach ($values as $key => $value) {
            $errorKey = $errorKeys[$value['definition']->key] ?? sprintf('pages.%s.properties.%s', $slug, $key);

            try {
                resolve(PropertyValueValidator::class)->validate($page, $value['data'], $definitions);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([$errorKey => Arr::flatten($exception->errors())]);
            } catch (PropertyValueValidationException $exception) {
                throw ValidationException::withMessages([$errorKey => $exception->getMessage()]);
            }

            if ($value['definition']->promotedField !== null) {
                throw ValidationException::withMessages([$errorKey => PropertyValueValidationException::promotedPropertyDirectWrite($value['data']->propertyKey, $value['definition']->promotedField)->getMessage()]);
            }
        }

        foreach ($values as $key => $value) {
            $stored = PagePropertyValue::query()
                ->where('site_id', $page->site_id)
                ->where('page_id', $page->getKey())
                ->where('property_definition_id', $value['definition']->definitionId)
                ->where('translation_id', $value['data']->translationId)
                ->where('position', $value['data']->position)
                ->first();

            $current = $stored instanceof PagePropertyValue
                ? PropertyValueData::fromPageValue($stored, $value['data']->propertyKey, $value['data']->type)
                : null;

            if (! $current instanceof PropertyValueData || ! PropertyValueEquality::sameColumns($current->toColumns(), $value['data']->toColumns())) {
                $changed[(string) $key] = ['before' => $current instanceof PropertyValueData ? $this->display($current->value, $current->currency, $current->unit) : null, 'after' => $this->display($value['data']->value, $value['data']->currency, $value['data']->unit)];
            }
        }

        return $changed;
    }

    private function display(mixed $value, ?string $currency = null, ?string $unit = null): ?string
    {
        $text = match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => null,
        };

        return $text === null ? null : trim($text . ' ' . ($currency ?? $unit ?? ''));
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{0: array<string, array{data: PropertyValueData, definition: EffectivePropertyDefinitionData}>, 1: array<string, string>}
     */
    private function values(Page $page, string $slug, array $properties, ?int $languageId): array
    {
        $definitions = ResolveEffectiveDefinitionsAction::run($page);
        $translationId = $page->translations()
            ->where('language_id', $languageId ?? $page->site?->language_id)
            ->value('id');
        $values = [];
        $errorKeys = [];

        foreach ($properties as $key => $input) {
            $key = (string) $key;
            $errorKey = sprintf('pages.%s.properties.%s', $slug, $key);
            $definition = $definitions->first(
                static fn (EffectivePropertyDefinitionData $candidate): bool => $candidate->key === $key || $candidate->qualifiedKey() === $key,
            );

            if (! $definition instanceof EffectivePropertyDefinitionData) {
                throw ValidationException::withMessages([
                    $errorKey => __('capell-core::properties.validation.not_attached_to_blueprint', ['property' => $key]),
                ]);
            }

            $shape = is_array($input) ? $input : ['value' => $input];
            $errorKeys[$definition->key] = $errorKey;
            $values[$key] = [
                'definition' => $definition,
                'data' => new PropertyValueData(
                    propertyKey: $definition->key,
                    type: $definition->type,
                    value: $shape['value'] ?? null,
                    currency: is_string($shape['currency'] ?? null) ? $shape['currency'] : null,
                    unit: is_string($shape['unit'] ?? null) ? $shape['unit'] : null,
                    translationId: $definition->localised && is_int($translationId) ? $translationId : null,
                ),
            ];
        }

        return [$values, $errorKeys];
    }
}
