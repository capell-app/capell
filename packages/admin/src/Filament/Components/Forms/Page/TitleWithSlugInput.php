<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Filament\Components\Forms\SlugInput;
use Capell\Admin\Filament\Components\Forms\TitleWithSlugInput as BaseTitleWithSlugInput;
use Capell\Admin\Filament\Components\Forms\TranslationLanguageSelect;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Support\Filament\RawState;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Exception;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Validation\Rules\Unique;

class TitleWithSlugInput
{
    public static function make(Schema $schema): Grid
    {
        return Grid::make(3)
            ->columnSpan(1)
            ->key('page-title-with-slug-input-wrapper')
            ->schema([
                BaseTitleWithSlugInput::make(
                    fieldTitle: 'title',
                    fieldSlug: 'slug',
                    urlPath: function (Get $get, ?string $state, string $operation, HasPageResource $livewire) use ($schema): string {
                        $siteId = $get('../../site_id');

                        if ((blank($siteId)) && in_array($operation, ['edit', 'editOption'], true)) {
                            $formData = RawState::array($schema->getRawState());
                            $siteId = $formData['site_id'] ?? null;
                        }

                        throw_unless($siteId, Exception::class, 'Site ID is required to generate URL path');

                        $languageId = $get('language_id');

                        if (blank($languageId)) {
                            return '';
                        }

                        /** @var Site $site */
                        $site = resolve(Site::class)::query()->find($siteId);

                        /** @var Language $language */
                        $language = resolve(Language::class)::query()->find($languageId);

                        $basePath = self::getBasePath($livewire, $site, $language);

                        $parentUuid = $get('../../parent_id');
                        if (blank($parentUuid)) {
                            return $basePath;
                        }

                        /** @var class-string<Page> $model */
                        $model = Page::class;

                        $parentPageId = $model::query()->select('id')
                            ->where('id', $parentUuid)
                            ->value('id');

                        $baseQuery = $model::query();

                        foreach (app()->tagged(PageTableExtender::TAG) as $extender) {
                            if ($extender instanceof PageTableExtender) {
                                $baseQuery = $extender->modifyQuery($baseQuery);
                            }
                        }

                        $descendants = $baseQuery
                            ->withWhereHas(
                                'translation',
                                fn (BuilderContract $query): BuilderContract => $query->where('language_id', $languageId),
                            )
                            ->where('site_id', (int) $siteId)
                            ->whereAncestorOf($parentPageId, true)
                            ->ordered()
                            ->get();

                        if ($descendants->isEmpty()) {
                            return $basePath;
                        }

                        $paths = [];
                        foreach ($descendants as $descendant) {
                            $paths[] = $descendant->translation->slug ?? '';
                        }

                        $endingSlash = $state === '/' ? '' : '/';

                        return $basePath . implode('/', $paths) . $endingSlash;
                    },
                    urlHost: function (Get $get, ?Translation $record): ?string {
                        $page = $record?->translatable;

                        return self::getSiteHost(
                            (int) $get('../../site_id'),
                            (int) $get('language_id'),
                            $page instanceof Pageable ? $page : null,
                        );
                    },
                    urlVisitLinkLabel: fn (?Translation $record): string => __('capell-admin::button.visit'),
                    urlVisitLinkRoute: fn (SlugInput $component, Translation $record): ?string => PageUrlPresenter::fullUrl($record->pageUrl),
                    titleLabel: __('capell-admin::form.page_title'),
                    titleExtraInputAttributes: ['class' => ''],
                    titleAutofocus: false,
                    titleAfterStateUpdated: function (?string $state, Get $get, Set $set): void {
                        if ($state === null || $state === '') {
                            return;
                        }

                        $namePath = '../../name';

                        $currentName = $get($namePath);
                        if ($currentName === null || $currentName === '') {
                            $set($namePath, $state);

                            return;
                        }

                        $set($namePath, $state);
                    },
                    slugLabel: '',
                    slugStatePath: 'meta.slug',
                    slugRuleUniqueParameters: [
                        'column' => 'meta->slug',
                        'table' => 'translations',
                        'modifyRuleUsing' => function (Unique $rule, Get $get, ?Translation $record, HasPageResource $livewire) use ($schema): void {
                            $language_id = $get('language_id');

                            $data = RawState::array($schema->getRawState());
                            $page = $record?->translatable;

                            /** @var class-string<resource> $resource */
                            $resource = $livewire::getResource();

                            /** @var class-string<Pageable<Page>&Model> $model */
                            $model = $page !== null ? $page::class : $resource::getModel();

                            $baseRecord = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

                            $modelId = resolve($model)->qualifyColumn('id');

                            $hasPageHierarchy = $model::hasPageHierarchy();

                            $parentId = $data['parent_id'] ?? $page->parent_id ?? null;
                            $siteId = $data['site_id'] ?? $page->site_id ?? null;

                            $id = $page?->getKey() !== null ? $page->getKey() : $baseRecord?->getKey();

                            /** @var list<int> $pageIds */
                            $pageIds = $model::query()
                                ->select($modelId)
                                ->join(
                                    'translations',
                                    fn (JoinClause $join): JoinClause => $join->on($modelId, '=', 'translations.translatable_id')
                                        ->where('translations.translatable_type', resolve($model)->getMorphClass()),
                                )
                                ->when(
                                    $siteId,
                                    fn (Builder $query): Builder => $query->where('site_id', $siteId),
                                )
                                ->when(
                                    $hasPageHierarchy,
                                    fn (Builder $query): Builder => $query->when(
                                        $parentId,
                                        fn (Builder $query): Builder => $query->where('parent_id', $parentId),
                                        fn (Builder $query): Builder => $query->whereNull('parent_id'),
                                    ),
                                )
                                ->whereNull(resolve($model)->qualifyColumn('deleted_at'))
                                ->when(
                                    $id,
                                    fn (Builder $query): Builder => $query->where($modelId, '!=', $id),
                                )
                                ->pluck($modelId)
                                ->all();

                            $rule->where('language_id', $language_id)
                                ->whereIn('translations.translatable_id', $pageIds);
                        },
                        'ignorable' => fn (?Model $record, string $operation): Model|null|false => in_array($operation, ['edit', 'editOption'], true) ? $record : false,
                    ],
                    slugIsReadonly: fn (?Translation $record): bool => (bool) ($record?->translatable->type?->meta['accessible'] ?? false),
                    slugRuleRegex: '/^[a-z0-9\-\_]|\/*$/',
                    // urlVisitLinkIcon: 'heroicon-o-eye'
                )
                    ->label(__('capell-admin::form.title'))
                    ->extraFieldWrapperAttributes([
                        'class' => 'page-title-with-slug-input',
                    ])
                    ->columnSpan(fn (Get $get): int => ((int) $get('language_id')) !== 0 ? 3 : 2)
                    ->registerActions(self::resolveExtenderActions())
                    ->afterLabel(fn (FusedGroup $component): ?Schema => self::resolveAfterLabelSchema($component)),

                TranslationLanguageSelect::make()
                    ->dehydratedWhenHidden()
                    ->withRelationship()
                    ->hidden(fn (?int $state): bool => $state !== null && $state !== 0),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    private static function resolveExtenderActions(): array
    {
        return resolve(AdminSchemaExtensionPipeline::class)->pageTitleActions();
    }

    private static function resolveAfterLabelSchema(FusedGroup $component): ?Schema
    {
        return resolve(AdminSchemaExtensionPipeline::class)->pageTitleAfterLabel($component);
    }

    /**
     * @param  Pageable<Page>|null  $page
     */
    private static function getSiteHost(int $site_id, ?int $language_id, ?Pageable $page): ?string
    {
        if ($language_id === null || $language_id === 0) {
            return null;
        }

        if ($page instanceof Pageable) {
            $siteDomain = $page->site->siteDomains->firstWhere('language_id', $language_id);
        } else {
            /** @var class-string<Site> $model */
            $model = Site::class;

            /** @var Site|null $site */
            $site = $model::query()
                ->whereKey($site_id)
                ->withWhereHas(
                    'siteDomain',
                    fn (BuilderContract $query): BuilderContract => $query
                        ->when(
                            $language_id,
                            fn (Builder $query): Builder => $query->whereNull('language_id')->orWhere('language_id', $language_id),
                        )
                        ->orderByDesc('default'),
                )
                ->orderByDesc('default')
                ->first();

            if ($site === null) {
                return null;
            }

            $siteDomain = $site->siteDomain;
        }

        if ($siteDomain === null) {
            return null;
        }

        return $siteDomain->full_url;
    }

    private static function getBasePath(HasPageResource $livewire, Site $site, Language $language): string
    {
        /** @var class-string<resource> $resource */
        $resource = $livewire::getResource();

        if (method_exists($resource, 'getBasePath')) {
            return $resource::getBasePath($site, $language);
        }

        return '/';
    }
}
