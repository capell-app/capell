<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Actions\Pages\ResolvePageJourneyLanguageAction;
use Capell\Admin\Filament\Components\Forms\TranslationsRepeater as BaseTranslationsRepeater;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Support\Filament\RawState;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\CapellCoreHelper;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

class TranslationsRepeater extends BaseTranslationsRepeater
{
    #[Override]
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
            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                if (is_array($data['meta'] ?? null)) {
                    $data['meta'] = array_filter($data['meta'], fn (mixed $value): bool => $value !== null);
                }

                return $data;
            })
            ->syncActiveTabWithServer()
            ->required()
            ->helperText(function (): ?string {
                $languageId = request()->query('language_id');
                if (! is_int($languageId) && ! is_string($languageId)) {
                    return null;
                }

                foreach ($this->getState() ?? [] as $row) {
                    if ((string) ($row['language_id'] ?? '') === (string) $languageId) {
                        return (string) __('capell-admin::page_journey.selected_language');
                    }
                }

                return (string) __('capell-admin::page_journey.missing_translation');
            })
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
            ->beforeAddAction(function (Action $action, array $data, array $state): bool {
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

                return ! $matchingData;
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

    #[Override]
    public function getDefaultTab(): ?int
    {
        $requestedTab = request()->query($this->getTabQueryStringKey());
        if (is_string($requestedTab) && array_key_exists($requestedTab, $this->getChildSchemas())) {
            return parent::getDefaultTab();
        }

        $state = RawState::array($this->getRootContainer()->getRawState());
        $record = $this->getRecord();
        $siteId = $state['site_id'] ?? ($record instanceof Page ? $record->site_id : null);
        $language = ResolvePageJourneyLanguageAction::run(is_numeric($siteId) ? (int) $siteId : null, request()->query('language_id'));
        $index = 0;
        foreach ($this->getState() ?? [] as $row) {
            $index++;
            if ($language !== null && (int) ($row['language_id'] ?? 0) === $language->id) {
                return $index;
            }
        }

        return parent::getDefaultTab();
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
        $site = SiteAccess::current()->query($model)
            ->with('translations:id,language_id,translatable_type,translatable_id')
            ->find($siteId, ['id']);

        $ids = $site->translations->pluck('language_id')->unique()->values()->toArray();

        return $query->whereIn('id', $ids);
    }
}
