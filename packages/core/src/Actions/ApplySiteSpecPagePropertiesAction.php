<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Exceptions\PropertyValueValidationException;
use Capell\Core\Models\Page;
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
 * @method static void run(Page $page, string $slug, array<string, mixed> $properties)
 */
final class ApplySiteSpecPagePropertiesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function handle(Page $page, string $slug, array $properties): void
    {
        if ($properties === []) {
            return;
        }

        $definitions = ResolveEffectiveDefinitionsAction::run($page);
        $translationId = $page->translations()->orderBy('id')->value('id');
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
            $values[] = new PropertyValueData(
                propertyKey: $definition->key,
                type: $definition->type,
                value: $shape['value'] ?? null,
                currency: is_string($shape['currency'] ?? null) ? $shape['currency'] : null,
                unit: is_string($shape['unit'] ?? null) ? $shape['unit'] : null,
                translationId: $definition->localised && is_int($translationId) ? $translationId : null,
            );
        }

        // One write for the whole page, so the import records a single page
        // version rather than one per property.
        try {
            SetPagePropertyValuesAction::run($page, $values);
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
}
