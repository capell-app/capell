<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Observers\PageObserver;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Refuses to restore a page from the bin while another live page on its site
 * holds the same value for a property that must be unique there. A page in
 * the bin does not hold its values, so another page may have taken one while
 * it was deleted; restoring it unchecked would leave two holders. Called from
 * {@see PageObserver::restoring()}, before anything is written.
 *
 * @method static void run(Page $page)
 */
final class AssertRestoredPagePropertyValuesAreUniqueAction
{
    use AsFake;
    use AsObject;

    /**
     * @throws ValidationException
     */
    public function handle(Page $page): void
    {
        $values = PagePropertyValue::query()
            ->where('site_id', $page->site_id)
            ->where('page_id', $page->id)
            ->with('propertyDefinition')
            ->get();

        foreach ($values as $value) {
            $definition = $value->propertyDefinition;
            if (! $definition instanceof PropertyDefinition) {
                continue;
            }

            if (! $definition->constraints instanceof PropertyConstraintsData) {
                continue;
            }

            if (! $definition->constraints->uniquePerSite) {
                continue;
            }

            $column = BuildPropertyValidationRulesAction::uniqueColumn($definition->type);
            $held = $column === null ? null : $value->getAttribute($column);

            if ($held === null) {
                continue;
            }

            $taken = PagePropertyValue::query()
                ->where('site_id', $page->site_id)
                ->where('property_definition_id', $definition->id)
                ->where($column, $held)
                ->where('page_id', '!=', $page->id)
                ->whereHas('page')
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    $definition->key => __('capell-core::properties.constraints.restore_not_unique', [
                        'property' => Str::headline($definition->key),
                    ]),
                ]);
            }
        }
    }
}
