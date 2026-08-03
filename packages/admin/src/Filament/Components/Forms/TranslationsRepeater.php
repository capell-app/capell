<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Actions\CheckTranslationCompletenessAction;
use Capell\Admin\Actions\GetFlatComponentKeysAction;
use Capell\Admin\Actions\MutateContentPresenterAction;
use Capell\Admin\Support\Filament\RawState;
use Capell\Admin\Support\Loader\LanguageLoader;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Models\Contracts\Translatable as TranslatableContract;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Closure;
use DateTimeInterface;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TranslationsRepeater extends RepeaterTabs
{
    protected function setUp(): void
    {
        parent::setUp();

        $languages_total = LanguageLoader::getTotalLanguages();

        $this->label(__('capell-admin::generic.translations'))
            ->hiddenLabel()
            ->relationship(
                name: 'translations',
                modifyQueryUsing: fn (Builder $query): Builder => $this->modifyTranslationsQuery($query),
            )
            ->setupActiveTabAfterStateHydrated()
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => $this->mutateTranslationRowBeforeFill($data))
            ->columnSpanFull()
            ->collapsible()
            ->afterStateHydrated(function (self $component, mixed $state): void {
                if (! is_array($state)) {
                    $component->state([]);
                }
            })
            ->defaultItems(0)
            ->required(
                function (self $component, ?Model $record): bool {
                    if ($record instanceof Model && $record->relationLoaded('blueprint')) {
                        $related = $record->getRelation('blueprint');

                        if ($related instanceof Blueprint) {
                            $admin = $related->getAttribute('admin');

                            return is_array($admin) && (bool) ($admin['require_translations'] ?? false);
                        }

                        return false;
                    }

                    $rawState = RawState::array($component->getRootContainer()->getRawState());
                    $type = CapellCoreHelper::getBlueprint(typeId: $rawState['blueprint_id'] ?? null);

                    if ($type instanceof Blueprint) {
                        $admin = $type->getAttribute('admin');

                        return is_array($admin) && (bool) ($admin['require_translations'] ?? false);
                    }

                    return false;
                },
            )
            ->minItems($this->getMinItemsClosure())
            ->rules([
                $this->getRulesClosure(),
            ])
            // Avoid negating non-boolean; treat null/empty explicitly
            ->addable(fn (?array $state): bool => ($state === null || $state === []) || ($languages_total > count($state)))
            ->cloneable(fn (?array $state): bool => (is_array($state)) && count($state) < $languages_total)
            ->createItems($this->getCreateItemsClosure())
            ->addAction(fn (Action $action): Action => $action->icon('heroicon-m-plus'))
            ->addActionLabel(__('capell-admin::button.add_translation'))
            ->itemLabel(function (RepeaterTabs $component, string $uuid, array $state): string {
                $language = static::getLanguage($component, $uuid, $state);

                if ($language instanceof Language) {
                    return $language->name;
                }

                return __('capell-admin::generic.tab');
            })
            ->itemBadge(function (RepeaterTabs $component, string $uuid): ?array {
                if ($component->getRelationship() === null) {
                    return null;
                }

                $records = $component->getCachedExistingRecords();

                /** @var Translation|null $record */
                $record = $records->get($uuid);

                if (! $record instanceof Translation) {
                    return null;
                }

                /**
                 * CheckTranslationCompletenessAction accepts the nested key shape generated
                 * by GetFlatComponentKeysAction, but its static contract is narrower.
                 *
                 * @var array<string, array<int, string>|null> $keys
                 */
                $keys = GetFlatComponentKeysAction::run($component);

                return $this->resolveItemBadge($record, $keys);
            })
            ->itemIcon(function (RepeaterTabs $component, string $uuid, array $state): ?string {
                $language = static::getLanguage($component, $uuid, $state);

                if (! $language instanceof Language) {
                    return null;
                }

                return 'flag-4x3-' . $language->flag;
            })
            ->contained();
    }

    /**
     * Decodes a single translation row's stored content for the page's effective
     * content structure before it fills the repeater. Filament types
     * mutateRelationshipDataBeforeFill() as a list of rows, but this repeater
     * receives and rewrites one row at a time — this method names that single-row
     * contract so it can be type-checked and exercised directly in tests.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateTranslationRowBeforeFill(array $data): array
    {
        $data['content'] = MutateContentPresenterAction::run(
            $data['content'] ?? [],
            $this->resolveContentStructure($this->getModelInstance()),
        );

        return $data;
    }

    /**
     * Resolves the tab badge for a single translation row. The badge closure only
     * has the repeater item uuid, so this method names the record-level contract
     * it delegates to and keeps it directly testable.
     *
     * @param  array<string, array<int, string>|null>  $keys
     * @return array{label: string, color: string, tooltip: string}|null
     */
    public function resolveItemBadge(Translation $record, array $keys): ?array
    {
        if ($record->language->isDefault()) {
            return null;
        }

        $percent = CheckTranslationCompletenessAction::run($record, $keys);

        if ($percent === null) {
            return null;
        }

        $defaultTranslation = $this->resolveDefaultTranslation($record);
        $isOutdated = $this->isOutdatedAgainstDefault($record, $defaultTranslation);

        if ($percent === 100) {
            if ($isOutdated) {
                return [
                    'label' => __('capell-admin::generic.translation_outdated'),
                    'color' => 'warning',
                    'tooltip' => __('capell-admin::generic.translation_outdated_info'),
                ];
            }

            if ($defaultTranslation instanceof Translation && $this->mirrorsDefault($record, $defaultTranslation, $keys)) {
                return [
                    'label' => __('capell-admin::generic.translation_untranslated'),
                    'color' => 'gray',
                    'tooltip' => __('capell-admin::generic.translation_untranslated_info'),
                ];
            }

            return [
                'label' => __('capell-admin::generic.translation_complete'),
                'color' => 'success',
                'tooltip' => __('capell-admin::generic.translation_complete_info'),
            ];
        }

        if ($isOutdated) {
            return [
                'label' => $percent . '% · ' . __('capell-admin::generic.translation_outdated'),
                'color' => $percent >= 50 ? 'warning' : 'danger',
                'tooltip' => __('capell-admin::generic.translation_outdated_info'),
            ];
        }

        return [
            'label' => $percent . '%',
            'color' => $percent >= 75 ? 'info' : ($percent >= 50 ? 'warning' : 'danger'),
            'tooltip' => __('capell-admin::generic.translation_incomplete_info'),
        ];
    }

    public function withoutRelationship(): static
    {
        return $this->dehydrated()
            ->loadStateFromRelationshipsUsing(null)
            ->saveRelationshipsUsing(fn (): false => false);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected static function getLanguage(RepeaterTabs $component, string $uuid, array $state): ?Language
    {
        // Avoid empty(); check strictly for presence and null
        $languageId = $state['language_id'] ?? null;

        if (! is_int($languageId) && (! is_string($languageId) || ! is_numeric($languageId))) {
            return null;
        }

        $languageId = (int) $languageId;

        if ($component->getRelationship() !== null) {
            $records = $component->getCachedExistingRecords();
            $record = $records->get($uuid);

            if ($record instanceof Translation) {
                $language = $record->getRelation('language');

                if ($language instanceof Language && $language->id === $languageId) {
                    return $language;
                }
            }
        }

        /** @var class-string<Language> $model */
        $model = Language::class;

        return $model::query()->find($languageId);
    }

    /**
     * @param  Builder<Language>  $query
     * @return Builder<Language>
     */
    protected function modifyCreateItemsQuery(Builder $query): Builder
    {
        return $query->select(['id', 'name', 'flag'])
            ->enabled()
            ->ordered();
    }

    private function resolveDefaultTranslation(Translation $record): ?Translation
    {
        $record->loadMissing('translatable');

        $translatable = $record->getRelation('translatable');

        if (! $translatable instanceof TranslatableContract) {
            return null;
        }

        $defaultTranslation = $translatable
            ->translations()
            ->whereRelation('language', 'default', true)
            ->first();

        return $defaultTranslation instanceof Translation ? $defaultTranslation : null;
    }

    private function isOutdatedAgainstDefault(Translation $record, ?Translation $defaultTranslation): bool
    {
        if (! $defaultTranslation instanceof Translation) {
            return false;
        }

        if ($defaultTranslation->is($record)) {
            return false;
        }

        $defaultUpdatedAt = $defaultTranslation->getAttribute('updated_at');
        $recordUpdatedAt = $record->getAttribute('updated_at');

        if (! $defaultUpdatedAt instanceof DateTimeInterface || ! $recordUpdatedAt instanceof DateTimeInterface) {
            return false;
        }

        return $defaultUpdatedAt > $recordUpdatedAt;
    }

    /**
     * A language added to a site starts as a clone of the default-language row
     * (see SetupSiteLanguageAction), so a byte-identical row is untranslated
     * rather than complete.
     *
     * @param  array<string, array<int, string>|null>  $keys
     */
    private function mirrorsDefault(Translation $record, Translation $defaultTranslation, array $keys): bool
    {
        if ($defaultTranslation->is($record)) {
            return false;
        }

        $defaultAttributes = $defaultTranslation->getAttributes();
        $recordAttributes = $record->getAttributes();

        $compared = 0;

        foreach (array_keys($keys) as $key) {
            if (! array_key_exists($key, $defaultAttributes) || ! array_key_exists($key, $recordAttributes)) {
                continue;
            }

            if ($defaultAttributes[$key] !== $recordAttributes[$key]) {
                return false;
            }

            $compared++;
        }

        return $compared > 0;
    }

    private function getMinItemsClosure(): Closure
    {
        return function (self $component, ?Model $record): int {
            $requiredTranslations = null;
            if ($record instanceof Model && $record->relationLoaded('blueprint')) {
                $related = $record->getRelation('blueprint');
                if ($related instanceof Blueprint) {
                    $admin = $related->getAttribute('admin');
                    $requiredTranslations = is_array($admin) ? ($admin['require_translations'] ?? null) : null;
                } else {
                    $requiredTranslations = null;
                }
            } else {
                $rawState = RawState::array($component->getRootContainer()->getRawState());
                $type = CapellCoreHelper::getBlueprint(typeId: $rawState['blueprint_id'] ?? null);
                if ($type instanceof Blueprint) {
                    $admin = $type->getAttribute('admin');
                    $requiredTranslations = is_array($admin) ? ($admin['require_translations'] ?? null) : null;
                }
            }

            if (is_array($requiredTranslations) && $requiredTranslations !== []) {
                $rawState = RawState::array($component->getRootContainer()->getRawState());
                $siteId = $rawState['site_id'] ?? null;
                $site = CapellCoreHelper::getSite($siteId, true);
                if (! $site instanceof Site) {
                    return 0;
                }

                $siteAdmin = $site->getAttribute('admin');
                $siteRequired = is_array($siteAdmin) ? ($siteAdmin['require_translations'] ?? null) : null;
                if (is_array($siteRequired) && $siteRequired !== []) {
                    return count($siteRequired);
                }
            }

            return 0;
        };
    }

    private function getRulesClosure(): Closure
    {
        return fn (self $component, ?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component, $record): void {
            $type = $this->resolveTypeRecord($record);

            $typeAdminRequire = false;
            if ($type instanceof Blueprint) {
                $typeAdmin = $type->getAttribute('admin');
                $typeAdminRequire = is_array($typeAdmin) && (bool) ($typeAdmin['require_translations'] ?? false);
            }

            if ($typeAdminRequire === false) {
                return;
            }

            $rawState = RawState::array($component->getRootContainer()->getRawState());
            $siteId = $rawState['site_id'] ?? null;
            $site = CapellCoreHelper::getSite($siteId, true);
            if (! $site instanceof Site) {
                return;
            }

            $siteAdmin = $site->getAttribute('admin');
            $siteRequired = is_array($siteAdmin) ? ($siteAdmin['require_translations'] ?? null) : null;
            if (! is_array($siteRequired) || $siteRequired === []) {
                return;
            }

            /** @var array<int, string> $requiredLanguageCodes */
            $requiredLanguageCodes = $siteRequired;

            $providedLanguageIds = array_map(
                fn (array $item): ?int => $item['language_id'] ?? null,
                is_array($value) ? $value : [],
            );

            $requiredLanguages = CapellCoreHelper::languagesByCodes($requiredLanguageCodes);
            $requiredLanguageIds = $requiredLanguages->pluck('id')->toArray();

            $missingLanguageIds = array_diff($requiredLanguageIds, $providedLanguageIds);

            if ($missingLanguageIds !== []) {
                $missingLanguages = $requiredLanguages->whereIn('id', $missingLanguageIds);
                $missingLanguageNames = $missingLanguages->pluck('name')->toArray();
                $fail(__('capell-admin::message.required_translation_languages_missing', [
                    'languages' => implode(', ', $missingLanguageNames),
                ]));
            }
        };
    }

    private function getCreateItemsClosure(): Closure
    {
        return function (self $component, ?array $state): array {
            $items = [];

            $selected = [];
            if (is_array($state)) {
                foreach ($state as $translation) {
                    if (isset($translation['language_id']) && is_numeric($translation['language_id'])) {
                        $selected[] = (int) $translation['language_id'];
                    }
                }
            }

            /** @var class-string<Language> $model */
            $model = Language::class;

            /** @var Builder<Language> $query */
            $query = $component->modifyCreateItemsQuery($model::query());

            $query->each(function (Language $language) use (&$items, $selected): void {
                if (in_array($language->id, $selected, true)) {
                    return;
                }

                $items[] = [
                    'id' => $language->id,
                    'label' => $language->name,
                    'icon' => 'flag-4x3-' . $language->flag,
                ];
            });

            return $items;
        };
    }

    /**
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    private function modifyTranslationsQuery(Builder $query): Builder
    {
        return $query
            ->with(['language'])
            ->select($query->getModel()->qualifyColumn('*'))
            ->join('languages', $query->getModel()->qualifyColumn('language_id'), '=', 'languages.id')
            ->whereNull('languages.deleted_at')
            ->orderByDesc('languages.default')
            ->orderBy('languages.order')
            ->orderBy('languages.name');
    }

    private function resolveTypeRecord(?Model $record): ?Blueprint
    {
        if ($record instanceof Blueprintable) {
            $record->loadMissing('blueprint');

            $type = $record->getRelation('blueprint');

            if ($type instanceof Blueprint) {
                return $type;
            }
        }

        $rawState = RawState::array($this->getRootContainer()->getRawState());
        $typeId = $rawState['blueprint_id'] ?? null;

        if (! $typeId) {
            return null;
        }

        return CapellCoreHelper::getBlueprint(typeId: $typeId);
    }

    private function resolveContentStructure(?Model $record): ?ContentStructure
    {
        $rawState = RawState::array($this->getRootContainer()->getRawState());
        $override = $rawState['content_structure_override'] ?? null;

        if (is_string($override) && $override !== '') {
            $contentStructure = ContentStructure::tryFrom($override);

            if ($contentStructure instanceof ContentStructure) {
                return $contentStructure;
            }
        }

        if ($record instanceof Page) {
            return $record->content_structure;
        }

        return $this->resolveTypeRecord($record)?->content_structure;
    }
}
