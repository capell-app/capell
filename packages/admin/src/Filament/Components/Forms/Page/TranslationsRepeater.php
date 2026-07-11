<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Filament\Components\Forms\TranslationsRepeater as BaseTranslationsRepeater;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Support\Filament\RawState;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\CapellCoreHelper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;

class TranslationsRepeater extends BaseTranslationsRepeater
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->relationship(
            name: 'translations',
            modifyQueryUsing: fn (Builder $query): Builder => $query
                ->with(['language', 'pageUrl.siteDomain'])
                ->select($query->getModel()->qualifyColumn('*'))
                ->join('languages', $query->getModel()->qualifyColumn('language_id'), '=', 'languages.id')
                ->whereNull('languages.deleted_at')
                ->orderByDesc('default')
                ->orderBy('languages.order')
                ->orderBy('languages.name'),
        )
            ->setupActiveTabAfterStateHydrated()
            ->required()
            ->persistTabInQueryString()
            ->default(function (self $component, Get $get, ?Model $record): array {
                $pageRecord = $record instanceof Pageable ? $record : null;

                return CapellCoreHelper::getSiteLanguagesForRecord($pageRecord, siteId: $get('../../site_id'))
                    ->mapWithKeys(fn (Language $language): array => [$language->id => [
                        'language_id' => $language->id,
                    ]])
                    ->all();
            })
            ->minItems(1)
            ->collapsed(function (Schema $schema, ?Pageable $record): bool {
                $languages = CapellCoreHelper::getSiteLanguagesForRecord($record);

                $state = RawState::array($schema->getRawState());

                /** @var Language|null $language */
                $language = $languages->firstWhere('id', $state['language_id'] ?? null);

                if ($language instanceof Language) {
                    return ! $language->default;
                }

                return false;
            })
            ->addable(function (EditRecord|CreateRecord|ListRecords|HasPageResource $livewire, ?Pageable $record, ?array $state, self $component): bool {
                $rootState = RawState::array($component->getRootContainer()->getRawState());
                $siteId = $rootState['site_id'] ?? null;
                $languages = CapellCoreHelper::getSiteLanguagesForRecord($record, $siteId);

                if ($state !== null && $languages->count() <= 1 && count($state) >= $languages->count()) {
                    return false;
                }

                $parentComponent = $component->getParentComponent();
                $rootContainer = $component->getContainer()->isRoot() || ! $parentComponent instanceof Component
                    ? $component->getContainer()
                    : $parentComponent->getContainer();

                $formData = RawState::array($rootContainer->getRawState());

                $translations = is_array($formData['translations'] ?? null) ? $formData['translations'] : [];

                $selected = [];
                foreach ($translations as $translation) {
                    if (! is_array($translation)) {
                        continue;
                    }

                    $selected[] = (int) $translation['language_id'];
                }

                $languageIds = $languages->pluck('id')->toArray();

                /** @var class-string<Language> $model */
                $model = Language::class;

                return $model::query()
                    ->when($selected, fn (Builder $query): Builder => $query->whereNotIn('id', $selected))
                    ->when($languageIds, fn (Builder $query): Builder => $query->whereIn('id', $languageIds))
                    ->exists();
            })
            ->addAction(fn (Action $action): Action => $action->color('primary')->icon('heroicon-m-plus'))
            ->addActionLabel(__('capell-admin::button.add_translation'))
            ->beforeAddAction(function (self $component, Action $action, array $data, array $state): bool {
                $languageId = (int) ($data['language_id'] ?? null);

                if ($languageId === 0) {
                    Notification::make('page_language_required')
                        ->warning()
                        ->title(__('capell-admin::message.page_language_required'))
                        ->send();

                    $action->halt();
                }

                $matchingData = collect($state)
                    ->filter(fn (array $item): bool => (int) $item['language_id'] === $languageId)
                    ->first();

                if ($matchingData) {
                    return false;
                }

                $parentComponent = $component->getParentComponent();

                if (! $parentComponent instanceof Component) {
                    return false;
                }

                $formData = RawState::array($parentComponent->getContainer()->getRawState());

                // Check all description languages exist in parent
                if ($formData['parent_id'] ?? null) {
                    /** @var class-string<Page> $model */
                    $model = Page::class;

                    $parent = $model::query()
                        ->with(['blueprint', 'translations' => fn (BuilderContract $query): BuilderContract => $query->with('language')])
                        ->firstWhere('id', $formData['parent_id']);

                    if (! $parent instanceof Page) {
                        return true;
                    }

                    $langIds = $parent->translations->pluck('language_id')->toArray();

                    if (! in_array($languageId, $langIds, true)) {
                        /** @var class-string<Language> $model */
                        $model = Language::class;

                        /** @var Language|null $language */
                        $language = $model::query()->find($languageId, ['name']);

                        Notification::make('page_language_parent')
                            ->warning()
                            ->title(__('capell-admin::message.page_language_parent', ['name' => $language->name ?? '']))
                            ->body(__('capell-admin::message.page_language_parent_info'))
                            ->actions([
                                Action::make('edit')
                                    ->button()
                                    ->label(__('capell-admin::generic.edit') . ' ' . Str::limit($parent->name, 30))
                                    ->url(GetEditPageResourceUrlAction::run($parent)),
                            ])
                            ->send();

                        $action->halt();
                    }
                }

                return true;
            })
            ->cloneable(fn (): bool => true)
            ->itemLabel(function (self $component, string $uuid, array $state, ?Pageable $record): string {
                $rootState = RawState::array($component->getRootContainer()->getRawState());
                $siteId = $rootState['site_id'] ?? null;
                $languages = CapellCoreHelper::getSiteLanguagesForRecord($record, $siteId);
                /** @var Language|null $language */
                $language = $languages->firstWhere('id', $state['language_id'] ?? null);

                if ($language instanceof Language) {
                    return $language->name;
                }

                return __('capell-admin::generic.tab');

            });
    }

    /**
     * @param  Builder<Language>  $query
     * @return Builder<Language>
     */
    #[Override]
    protected function modifyCreateItemsQuery(Builder $query): Builder
    {
        parent::modifyCreateItemsQuery($query);

        $rootState = RawState::array($this->getRootContainer()->getRawState());
        $siteId = $rootState['site_id'] ?? null;

        if (in_array($siteId, [null, 0, ''], true)) {
            return $query;
        }

        /** @var class-string<Site> $model */
        $model = Site::class;

        /** @var Site $site */
        $site = $model::query()
            ->with('translations:id,language_id,translatable_type,translatable_id')
            ->find($siteId, ['id']);

        $ids = $site->translations->pluck('language_id')->unique()->values()->toArray();

        return $query->whereIn('id', $ids);
    }
}
