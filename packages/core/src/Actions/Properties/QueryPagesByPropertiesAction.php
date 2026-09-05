<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\AgentPageQueryData;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\TranslatableType;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Properties\PropertyValuePrecedenceExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** SQL-backed public property query using the same precedence as the resolver. */
final class QueryPagesByPropertiesAction
{
    use AsFake;
    use AsObject;

    /** @return LengthAwarePaginator<int, Page> */
    public function handle(Site $site, AgentPageQueryData $input): LengthAwarePaginator
    {
        $set = PropertySet::query()->where('key', $input->set)->first();
        if (! $set instanceof PropertySet || ! $this->validInput($input)) {
            $this->invalid();
        }

        $attachments = BlueprintPropertySet::query()->where('property_set_id', $set->id)->get();
        $query = Page::query()->published()
            ->whereHas('blueprint', static fn (Builder $blueprint): Builder => $blueprint->enabled()->accessible())
            ->where('pages.site_id', $site->id)
            ->where(function (Builder $eligible) use ($attachments, $set, $site): void {
                $eligible->whereIn('blueprint_id', $attachments->pluck('blueprint_id'))
                    ->orWhereHas('terms.taxonomy', static fn (Builder $taxonomy): Builder => $taxonomy
                        ->where('site_id', $site->id)->where('property_set_id', $set->id));
            })
            ->when($input->publicUrlRequired, function (Builder $pages) use ($site, $input): void {
                // Apply URL eligibility before paginate(), because the
                // controller can only represent URL-addressable pages.
                $pages->whereHas('pageUrls', function (Builder $urls) use ($site, $input): void {
                    $urls->where('site_id', $site->id)->where('status', true)
                        ->where(static fn (Builder $url): Builder => $url->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
                        ->whereHas('translation', static fn (Builder $translation): Builder => $translation
                            ->when($input->languageId !== null, static fn (Builder $locale): Builder => $locale->where('language_id', $input->languageId)))
                        ->whereHas('siteDomain', static fn (Builder $domain): Builder => $domain->where('status', true));
                    if ($input->languageId !== null) {
                        $urls->where('language_id', $input->languageId);
                    }
                });
            });

        $sortKey = $input->sort === null ? null : ltrim($input->sort, '-');
        $keys = array_unique([...array_keys($input->filters), ...($sortKey === null ? [] : [$sortKey])]);

        foreach ($keys as $key) {
            if (! is_string($key)) {
                $this->invalid();
            }

            $definition = $set->definitions()->where('key', $key)->first();
            if (! $definition instanceof PropertyDefinition || ! $this->supportedKey($key)) {
                $this->invalid();
            }

            $query->where(function (Builder $visible) use ($attachments, $definition, $site, $set): void {
                $visible->whereIn('blueprint_id', $this->visibleBlueprints($attachments, $definition));
                if ($definition->agent_visible) {
                    // A blueprint attachment owns curation when present. A
                    // taxonomy must not bypass an explicit visibility override.
                    $visible->orWhere(function (Builder $inherited) use ($attachments, $site, $set): void {
                        $inherited->whereNotIn('blueprint_id', $attachments->pluck('blueprint_id'))
                            ->whereHas('terms.taxonomy', static fn (Builder $taxonomy): Builder => $taxonomy
                                ->where('site_id', $site->id)->where('property_set_id', $set->id));
                    });
                }
            });
            $constraints = $input->filters[$key] ?? [];
            $this->validateConstraints($definition, $constraints);

            if ($constraints !== []) {
                $this->whereResolvedValue($query, $definition, $constraints, $input->languageId);
            }

            if ($sortKey === $key) {
                $this->orderByResolvedValue($query, $definition, $input->languageId, str_starts_with((string) $input->sort, '-'));
            }
        }

        return $query->orderBy('pages.id')->paginate($input->size, ['pages.*'], 'page[number]', $input->page);
    }

    /**
     * @param  EloquentCollection<int, BlueprintPropertySet>  $attachments
     * @return list<int>
     */
    private function visibleBlueprints(EloquentCollection $attachments, PropertyDefinition $definition): array
    {
        /** @var list<int> $ids */
        $ids = [];
        foreach ($attachments as $attachment) {
            $effective = ResolveEffectiveDefinitionsAction::run(new Page(['blueprint_id' => $attachment->blueprint_id]))->first(
                fn (EffectivePropertyDefinitionData $candidate): bool => $candidate->definitionId === $definition->id,
            );
            if ($effective?->agentVisible) {
                $ids[] = $attachment->blueprint_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param  Builder<Page>  $pages
     * @param  array<string, mixed>  $constraints
     */
    private function whereResolvedValue(Builder $pages, PropertyDefinition $definition, array $constraints, ?int $languageId): void
    {
        $pages->where(function (Builder $pageQuery) use ($definition, $constraints, $languageId): void {
            $ownMatch = $this->pageValues($definition, $languageId);
            $this->applyConstraints($ownMatch, $definition, $constraints, 'page_property_values');
            $pageQuery->whereExists($ownMatch->toBase()->selectRaw('1'))
                ->orWhere(function (Builder $fallback) use ($definition, $constraints, $languageId): void {
                    $ownValues = $this->pageValues($definition, $languageId);
                    $fallback->whereNotExists($ownValues->toBase()->selectRaw('1'));
                    $termValue = $this->winningTermValue($definition);
                    $this->applyConstraints($termValue, $definition, $constraints, 'term_values');
                    $fallback->whereExists($termValue->toBase()->selectRaw('1'));
                });
        });
    }

    /** @return Builder<PagePropertyValue> */
    private function pageValues(PropertyDefinition $definition, ?int $languageId): Builder
    {
        $values = PagePropertyValue::query()
            ->whereColumn('page_property_values.page_id', 'pages.id')
            ->whereColumn('page_property_values.site_id', 'pages.site_id')
            ->where('property_definition_id', $definition->id);
        if ($definition->localised) {
            if ($languageId === null) {
                $values->whereNull('translation_id');
            } else {
                $values->where(function (Builder $selected) use ($languageId): void {
                    $selected->whereExists($this->translationForValue('page_property_values', $languageId))
                        ->orWhere(function (Builder $fallback) use ($languageId): void {
                            $fallback->whereNull('translation_id')->whereNotExists($this->preferredPageValue('page_property_values', $languageId));
                        });
                });
            }
        }

        if (! $definition->multiple) {
            // Match the resolver's deterministic scalar winner, even if legacy
            // nullable identities contain more than one row.
            $values->where('page_property_values.id', function (QueryBuilder $first) use ($definition): void {
                $first->select('first_values.id')->from('page_property_values as first_values')
                    ->whereColumn('first_values.page_id', 'pages.id')
                    ->whereColumn('first_values.site_id', 'pages.site_id')
                    ->where('first_values.property_definition_id', $definition->id);
                if ($definition->localised) {
                    $first->where(function (QueryBuilder $locale): void {
                        $locale->whereColumn('first_values.translation_id', 'page_property_values.translation_id')
                            ->orWhere(function (QueryBuilder $default): void {
                                $default->whereNull('first_values.translation_id')->whereNull('page_property_values.translation_id');
                            });
                    });
                }

                $first->orderBy('first_values.position')->orderBy('first_values.id')->limit(1);
            });
        }

        return $values;
    }

    /** @return Builder<Translation> */
    private function translationForValue(string $valueAlias, int $languageId): Builder
    {
        return Translation::query()
            ->whereColumn('translations.id', $valueAlias . '.translation_id')
            ->whereColumn('translations.translatable_id', $valueAlias . '.page_id')
            ->where('translations.translatable_type', TranslatableType::Page->value)
            ->where('translations.language_id', $languageId);
    }

    /** @return Builder<PagePropertyValue> */
    private function preferredPageValue(string $valueAlias, int $languageId): Builder
    {
        return PagePropertyValue::query()->from('page_property_values as preferred_values')
            ->whereColumn('preferred_values.page_id', $valueAlias . '.page_id')
            ->whereColumn('preferred_values.site_id', $valueAlias . '.site_id')
            ->whereColumn('preferred_values.property_definition_id', $valueAlias . '.property_definition_id')
            ->whereExists(function (QueryBuilder $translations) use ($languageId): void {
                $translations->selectRaw('1')->from('translations as preferred_translations')
                    ->whereColumn('preferred_translations.id', 'preferred_values.translation_id')
                    ->whereColumn('preferred_translations.translatable_id', 'preferred_values.page_id')
                    ->where('preferred_translations.translatable_type', TranslatableType::Page->value)
                    ->where('preferred_translations.language_id', $languageId);
            });
    }

    /** @return Builder<TermPropertyValue> */
    private function winningTermValue(PropertyDefinition $definition): Builder
    {
        return TermPropertyValue::query()->from('term_property_values as term_values')
            ->join('page_term as term_assignments', 'term_assignments.term_id', '=', 'term_values.term_id')
            ->join('terms as inherited_terms', 'inherited_terms.id', '=', 'term_values.term_id')
            ->join('taxonomies as inherited_taxonomies', 'inherited_taxonomies.id', '=', 'inherited_terms.taxonomy_id')
            ->whereColumn('term_assignments.page_id', 'pages.id')
            ->whereColumn('inherited_taxonomies.site_id', 'pages.site_id')
            ->where('term_values.property_definition_id', $definition->id)
            ->whereNotExists(function (QueryBuilder $earlier) use ($definition): void {
                $earlier->selectRaw('1')->from('term_property_values as earlier_values')
                    ->join('page_term as earlier_assignments', 'earlier_assignments.term_id', '=', 'earlier_values.term_id')
                    ->join('terms as earlier_terms', 'earlier_terms.id', '=', 'earlier_values.term_id')
                    ->join('taxonomies as earlier_taxonomies', 'earlier_taxonomies.id', '=', 'earlier_terms.taxonomy_id')
                    ->whereColumn('earlier_assignments.page_id', 'pages.id')
                    ->whereColumn('earlier_taxonomies.site_id', 'pages.site_id')
                    ->where('earlier_values.property_definition_id', $definition->id)
                    ->where(function (QueryBuilder $order) use ($definition): void {
                        $order->whereColumn('earlier_taxonomies.position', '<', 'inherited_taxonomies.position')
                            ->orWhere(function (QueryBuilder $sameTaxonomy): void {
                                $sameTaxonomy->whereColumn('earlier_taxonomies.position', 'inherited_taxonomies.position')
                                    ->whereColumn('earlier_assignments.position', '<', 'term_assignments.position');
                            })
                            ->orWhere(function (QueryBuilder $sameAssignment): void {
                                $sameAssignment->whereColumn('earlier_taxonomies.position', 'inherited_taxonomies.position')
                                    ->whereColumn('earlier_assignments.position', 'term_assignments.position')
                                    ->whereColumn('earlier_terms.id', '<', 'inherited_terms.id');
                            });
                        if ($definition->multiple) {
                            return;
                        }

                        $order->orWhere(function (QueryBuilder $sameTerm): void {
                            $sameTerm->whereColumn('earlier_taxonomies.position', 'inherited_taxonomies.position')
                                ->whereColumn('earlier_assignments.position', 'term_assignments.position')
                                ->whereColumn('earlier_terms.id', 'inherited_terms.id')
                                ->whereColumn('earlier_values.position', '<', 'term_values.position');
                        })
                            ->orWhere(function (QueryBuilder $samePosition): void {
                                $samePosition->whereColumn('earlier_taxonomies.position', 'inherited_taxonomies.position')
                                    ->whereColumn('earlier_assignments.position', 'term_assignments.position')
                                    ->whereColumn('earlier_terms.id', 'inherited_terms.id')
                                    ->whereColumn('earlier_values.position', 'term_values.position')
                                    ->whereColumn('earlier_values.id', '<', 'term_values.id');
                            });
                    });
            });
    }

    /** @param  Builder<Page>  $pages */
    private function orderByResolvedValue(Builder $pages, PropertyDefinition $definition, ?int $languageId, bool $descending): void
    {
        $ownPresence = $this->pageValues($definition, $languageId)->selectRaw('1');
        $own = $this->pageValues($definition, $languageId)->select($this->column($definition->type))->orderBy('position')->orderBy('id')->limit(1);
        $term = $this->winningTermValue($definition)->select('term_values.' . $this->column($definition->type))->orderBy('term_values.position')->orderBy('term_values.id')->limit(1);
        $direction = $descending ? 'desc' : 'asc';
        // Presence, rather than COALESCE, preserves page precedence when a
        // stored page value is null: the resolver does not replace it with a
        // term value merely because its scalar projection is null.
        $expression = new PropertyValuePrecedenceExpression($ownPresence->toBase(), $own->toBase(), $term->toBase());
        $pages->getQuery()->orderBy($expression, $direction)->addBinding($expression->bindings(), 'order');
    }

    /**
     * @param  Builder<PagePropertyValue>|Builder<TermPropertyValue>  $values
     * @param  array<string, mixed>  $constraints
     */
    private function applyConstraints(Builder $values, PropertyDefinition $definition, array $constraints, string $alias): void
    {
        $column = $alias . '.' . $this->column($definition->type);
        foreach ($constraints as $operator => $value) {
            if ($operator === 'currency' || $operator === 'unit') {
                $values->where($alias . '.' . $operator, '=', $value);
            } elseif ($operator === 'in') {
                if (! is_array($value)) {
                    $this->invalid();
                }

                $values->whereIn($column, array_map(fn (mixed $item): mixed => $this->normaliseValue($definition->type, $item), $value));
            } else {
                $values->where($column, ['eq' => '=', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$operator], $this->normaliseValue($definition->type, $value));
            }
        }
    }

    private function validateConstraints(PropertyDefinition $definition, mixed $constraints): void
    {
        if (! is_array($constraints) || count($constraints) > 8) {
            $this->invalid();
        }

        $hasUnit = false;
        $hasCurrency = false;
        foreach ($constraints as $operator => $value) {
            if (! is_string($operator) || ! in_array($operator, ['eq', 'lt', 'lte', 'gt', 'gte', 'in', 'currency', 'unit'], true)) {
                $this->invalid();
            }

            if ($operator === 'currency') {
                $hasCurrency = true;
                if ($definition->type !== PropertyType::Money || ! is_string($value) || preg_match('/\A[A-Z]{3}\z/', $value) !== 1) {
                    $this->invalid();
                }

                continue;
            }

            if ($operator === 'unit') {
                $hasUnit = true;
                $allowed = $definition->unit_config['allowed'] ?? [];
                if (! $definition->type->requiresUnit() || ! is_string($value) || ! is_array($allowed) || ! in_array($value, $allowed, true)) {
                    $this->invalid();
                }

                continue;
            }

            $items = $operator === 'in' ? $value : [$value];
            if (! is_array($items) || $items === [] || count($items) > 20) {
                $this->invalid();
            }

            foreach ($items as $item) {
                if (! is_scalar($item) || mb_strlen((string) $item) > 500 || ! $this->valueMatchesType($definition->type, $item)) {
                    $this->invalid();
                }
            }
        }

        if ($definition->type === PropertyType::Money && $constraints !== [] && ! $hasCurrency) {
            $this->invalid();
        }

        if ($definition->type->requiresUnit() && $constraints !== [] && ! $hasUnit) {
            $this->invalid();
        }
    }

    private function valueMatchesType(PropertyType $type, mixed $value): bool
    {
        if ($type->isNumeric()) {
            return is_numeric($value) && is_finite((float) $value);
        }

        if ($type->isBoolean()) {
            return is_bool($value) || in_array(strtolower((string) $value), ['0', '1', 'true', 'false'], true);
        }

        if ($type->isTemporal()) {
            return is_string($value) && Date::parse($value)->getTimestamp() !== false;
        }

        return $type->isText();
    }

    private function normaliseValue(PropertyType $type, mixed $value): mixed
    {
        if ($type->isBoolean()) {
            return in_array(strtolower((string) $value), ['1', 'true'], true) ? 1 : 0;
        }

        return $type->isNumeric() ? (float) $value : $value;
    }

    private function column(PropertyType $type): string
    {
        return match (true) {
            $type->isNumeric() => 'value_number',
            $type->isBoolean() => 'value_boolean',
            $type->isTemporal() => 'value_datetime',
            $type->isText() => 'value_text',
            default => $this->invalid(),
        };
    }

    private function supportedKey(string $key): bool
    {
        return preg_match('/\A[a-zA-Z0-9_-]+\z/', $key) === 1;
    }

    private function validInput(AgentPageQueryData $input): bool
    {
        return preg_match('/\A[a-zA-Z0-9._-]+\z/', $input->set) === 1 && count($input->filters) <= 10
            && $input->size >= 1 && $input->size <= 50 && $input->page >= 1 && $input->page <= 1000
            && ($input->sort === null || preg_match('/\A-?[a-zA-Z0-9_-]+\z/', $input->sort) === 1);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['filter' => __('capell-core::agent.invalid_query')]);
    }
}
