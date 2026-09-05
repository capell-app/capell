<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\AgentPropertyEntryData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Lorisleiva\Actions\Concerns\AsObject;
use stdClass;

final class BrowseAgentTaxonomiesAction
{
    use AsObject;

    /** @return LengthAwarePaginator<int, array{key: string, name: string, hierarchical: bool}>|LengthAwarePaginator<int, array{slug: string, name: string, parent: string|null, properties: stdClass}> */
    public function handle(Site $site, ?string $key = null, int $page = 1): LengthAwarePaginator
    {
        if ($key === null) {
            return Taxonomy::query()->where('site_id', $site->id)->orderBy('position')->orderBy('id')
                ->paginate(50, ['*'], 'page', $page)
                ->through(static fn (Taxonomy $taxonomy): array => [
                    'key' => $taxonomy->key,
                    'name' => $taxonomy->name,
                    'hierarchical' => $taxonomy->hierarchical,
                ]);
        }

        $taxonomy = Taxonomy::query()->where('site_id', $site->id)->where('key', $key)->firstOrFail();
        $definitions = $taxonomy->propertySet?->definitions()->where('agent_visible', true)->get()->keyBy('id');

        return $taxonomy->terms()->with(['parent', 'propertyValues'])->orderBy('id')
            ->paginate(50, ['*'], 'page', $page)
            ->through(function (Term $term) use ($taxonomy, $definitions): array {
                $properties = [];
                /** @var TermPropertyValue $row */
                foreach ($term->propertyValues as $row) {
                    $definition = $definitions?->get($row->property_definition_id);
                    if (! $definition instanceof PropertyDefinition) {
                        continue;
                    }

                    if ($definition->semantic === null) {
                        continue;
                    }

                    if (preg_match('/\Aschema:([A-Za-z][A-Za-z0-9]*)\z/', (string) $definition->semantic, $matches) !== 1) {
                        continue;
                    }

                    $value = match (true) {
                        $definition->type->isNumeric() => $row->value_number !== null ? (float) $row->value_number : null,
                        $definition->type->isBoolean() => $row->value_boolean,
                        $definition->type->isTemporal() => $row->value_datetime,
                        default => $row->value_text,
                    };
                    $projected = ProjectAgentSchemaValueAction::run(new AgentPropertyEntryData(
                        qualifiedKey: '',
                        semantic: $definition->semantic,
                        type: $definition->type,
                        value: $value,
                        currency: $row->currency,
                        unit: $row->unit,
                        position: $row->position,
                        referenceId: match ($definition->type) {
                            PropertyType::TermReference => $row->referenced_term_id,
                            PropertyType::EntryReference => $row->referenced_page_id,
                            PropertyType::Media => $row->media_id,
                            default => null,
                        },
                    ), $taxonomy->site_id);
                    if ($projected !== null) {
                        $properties[$matches[1]][] = $projected;
                    }
                }

                foreach ($properties as $semantic => $values) {
                    $properties[$semantic] = count($values) === 1 ? $values[0] : $values;
                }

                return [
                    'slug' => $term->slug,
                    'name' => $term->name,
                    'parent' => $taxonomy->hierarchical && $term->parent?->taxonomy_id === $taxonomy->id ? $term->parent->slug : null,
                    'properties' => (object) $properties,
                ];
            });
    }
}
