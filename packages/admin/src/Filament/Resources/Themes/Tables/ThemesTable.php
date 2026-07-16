<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Themes\Tables;

use Capell\Admin\Actions\Themes\CreateThemePreviewUrlAction;
use Capell\Admin\Actions\Themes\ResolveThemeEditorStateAction;
use Capell\Admin\Actions\Themes\SetActiveThemeForSitesAction;
use Capell\Admin\Data\Themes\SetActiveThemeForSitesData;
use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Components\Tables\Filters\StatusFilter;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Admin\Support\SiteScope;
use Capell\Admin\Support\Themes\ThemeLibraryRuntime;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Layout\View as LayoutView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

class ThemesTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->defaultSortOptionLabel(__('capell-admin::table.name'))
            ->columns(static::getTableColumns())
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->emptyStateHeading((string) __('capell-admin::table.theme_empty_heading'))
            ->emptyStateDescription((string) __('capell-admin::table.theme_empty_description'))
            ->emptyStateIcon('heroicon-o-swatch')
            ->filters([
                SelectFilter::make('blueprint_id')
                    ->label(__('capell-admin::form.theme_type'))
                    ->relationship(
                        name: 'blueprint',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->enabled()->themeType(),
                    ),
                StatusFilter::make('status'),
                TrashedFilter::make(),
            ])
            ->recordClasses(fn (): string => 'capell-theme-card-record')
            ->recordActions([
                Action::make('previewTheme')
                    ->label(__('capell-admin::theme-library.actions.preview'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconButton()
                    ->modalHeading(fn (Theme $record): string => (string) __('capell-admin::theme-library.actions.preview_theme', ['theme' => $record->name]))
                    ->modalDescription(__('capell-admin::theme-library.help.preview'))
                    ->modalSubmitActionLabel(__('capell-admin::theme-library.actions.open_preview'))
                    ->modalWidth(Width::Medium)
                    ->schema(self::previewSchema())
                    ->action(function (Theme $record, array $data): mixed {
                        $site = self::findScopedSite((int) ($data['site_id'] ?? 0));
                        $page = $site instanceof Site
                            ? self::findScopedPage((int) ($data['page_id'] ?? 0), $site)
                            : null;

                        if (! $site instanceof Site || ! $page instanceof Page) {
                            return null;
                        }

                        return redirect()->away(CreateThemePreviewUrlAction::run(
                            theme: $record,
                            site: $site,
                            page: $page,
                            presetKey: is_string($data['preset_key'] ?? null) ? $data['preset_key'] : null,
                        ));
                    }),
                Action::make('applyTheme')
                    ->label(__('capell-admin::theme-library.actions.apply'))
                    ->icon('heroicon-o-check-circle')
                    ->iconButton()
                    ->modalHeading(fn (Theme $record): string => (string) __('capell-admin::theme-library.actions.apply_theme', ['theme' => $record->name]))
                    ->modalDescription(__('capell-admin::theme-library.help.apply'))
                    ->modalSubmitActionLabel(__('capell-admin::theme-library.actions.confirm_apply'))
                    ->modalWidth(Width::Medium)
                    ->disabled(fn (Theme $record): bool => ! resolve(ThemeLibraryRuntime::class)->diagnostics($record->key, theme: $record)->isValid())
                    ->schema(self::applySchema())
                    ->action(function (Theme $record, array $data): void {
                        if (! resolve(ThemeLibraryRuntime::class)->diagnostics($record->key, theme: $record)->isValid()) {
                            throw ValidationException::withMessages([
                                'theme' => __('capell-admin::theme-library.validation.diagnostics_block_apply'),
                            ]);
                        }

                        $scope = $data['scope'] instanceof ThemeActivationScope
                            ? $data['scope']
                            : (ThemeActivationScope::tryFrom((string) ($data['scope'] ?? '')) ?? ThemeActivationScope::Global);
                        $rawSiteIds = is_array($data['site_ids'] ?? null) ? $data['site_ids'] : [];
                        $siteIds = array_values(collect($rawSiteIds)
                            ->filter(fn (mixed $siteId): bool => is_numeric($siteId))
                            ->map(fn (mixed $siteId): int => (int) $siteId)
                            ->values()
                            ->all());

                        if ($scope === ThemeActivationScope::Global && ! self::currentActorCanApplyGlobally()) {
                            throw ValidationException::withMessages([
                                'scope' => __('capell-admin::theme-library.validation.global_scope_forbidden'),
                            ]);
                        }

                        if ($scope === ThemeActivationScope::SelectedSites) {
                            if ($siteIds === []) {
                                throw ValidationException::withMessages([
                                    'site_ids' => __('capell-admin::theme-library.validation.selected_sites_required'),
                                ]);
                            }

                            $siteIds = self::authorizedSiteIds($siteIds);
                        }

                        SetActiveThemeForSitesAction::run(new SetActiveThemeForSitesData(
                            themeId: (int) $record->getKey(),
                            scope: $scope,
                            siteIds: $siteIds,
                        ));
                    }),
                self::viewThemeDiagnosticsAction(),
                EditAction::make()
                    ->label(__('capell-admin::theme-library.actions.customize'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->slideOver()
                    ->modalWidth(Width::ScreenLarge)
                    ->mutateRecordDataUsing(fn (array $data, Theme $record): array => self::editorRecordData($record, $data))
                    ->before(function (ManageThemes $livewire, Theme $record, array $data): void {
                        if ($record->colorsDifferFrom($data['meta']['colors'] ?? [])) {
                            $livewire->notifyThemeColorsChanged($record);
                        }
                    }),
                ActionGroup::make([
                    ReplicateAction::make(),
                    DeleteAction::make()
                        ->before(function (ManageThemes $livewire, DeleteAction $action, Theme $record): void {
                            if (! $livewire->validateDelete($record)) {
                                $action->cancel();
                            }
                        }),
                ])
                    ->color('gray'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->before(function (ManageThemes $livewire, DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void {
                        $records->each(function (Theme $record) use ($livewire, $action): void {
                            if (! $livewire->validateDelete($record)) {
                                $action->cancel();
                            }
                        });
                    }),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function editorRecordData(Theme $theme, array $data): array
    {
        $state = ResolveThemeEditorStateAction::run($theme);
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $admin = is_array($data['admin'] ?? null) ? $data['admin'] : [];

        $meta['editor'] = $state->metaEditor();
        $admin['editor'] = $state->adminEditor();

        return [
            ...$data,
            'meta' => $meta,
            'admin' => $admin,
        ];
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        /** @var view-string $themeCardView */
        $themeCardView = 'capell-admin::filament.resources.themes.theme-library-card';

        return [
            LayoutView::make($themeCardView),
            TextColumn::make('name')
                ->label(__('capell-admin::table.name'))
                ->formatStateUsing(fn (): string => '')
                ->searchable()
                ->sortable(),
            TextColumn::make('editor_active_preset')
                ->label(__('capell-admin::theme-library.labels.active_preset'))
                ->state(fn (Theme $record): ?string => self::activePresetLabel($record))
                ->badge()
                ->placeholder('-')
                ->hidden(),
            TextColumn::make('sites_count')
                ->label(__('capell-admin::theme-library.labels.sites'))
                ->counts('sites')
                ->numeric()
                ->sortable()
                ->hidden(),
            StatusIconColumn::make('status')
                ->hidden(),
            TextColumn::make('diagnostics')
                ->label(__('capell-admin::theme-library.sections.diagnostics'))
                ->state(fn (Theme $record): string => resolve(ThemeLibraryRuntime::class)->diagnostics($record->key, theme: $record)->badgeLabel())
                ->badge()
                ->color(fn (Theme $record): string => resolve(ThemeLibraryRuntime::class)->diagnostics($record->key, theme: $record)->badgeColor())
                ->action(self::viewThemeDiagnosticsAction())
                ->hidden(),
            TextColumn::make('key')
                ->label(__('capell-admin::table.key'))
                ->searchable()
                ->hidden(),
            TextColumn::make('package')
                ->label(__('capell-admin::theme-library.labels.package'))
                ->state(fn (Theme $record): string => resolve(ThemeLibraryRuntime::class)->installedCard($record)->package)
                ->hidden(),
        ];
    }

    private static function viewThemeDiagnosticsAction(): Action
    {
        return Action::make('viewThemeDiagnostics')
            ->label(__('capell-admin::theme-library.sections.diagnostics'))
            ->icon('heroicon-o-clipboard-document-check')
            ->iconButton()
            ->color(fn (Theme $record): string => resolve(ThemeLibraryRuntime::class)->diagnostics($record->key, theme: $record)->badgeColor())
            ->requiresConfirmation()
            ->modalHeading(fn (Theme $record): string => (string) __('capell-admin::theme-library.actions.diagnostics_for_theme', ['theme' => $record->name]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-admin::button.close'))
            ->modalWidth(Width::Medium)
            ->modalContent(fn (Theme $record): View => view(
                'capell-admin::filament.resources.themes.theme-diagnostics-modal',
                [
                    'diagnostics' => resolve(ThemeLibraryRuntime::class)->diagnostics($record->key, theme: $record),
                ],
            ))
            ->action(fn (): null => null);
    }

    private static function activePresetLabel(Theme $theme): ?string
    {
        $presetKey = data_get($theme->meta, 'editor.preset.active');

        if (! is_string($presetKey) || trim($presetKey) === '') {
            return null;
        }

        $definition = resolve(ThemeLibraryRuntime::class)->definition($theme->key);

        if ($definition !== null) {
            $options = $definition->presetOptions();

            return $options[$presetKey] ?? str($presetKey)->headline()->toString();
        }

        return str($presetKey)->headline()->toString();
    }

    /** @return array<int, mixed> */
    private static function previewSchema(): array
    {
        return [
            Select::make('site_id')
                ->label(__('capell-admin::form.site'))
                ->searchable()
                ->options(fn (): array => self::siteOptions(limit: 50))
                ->getSearchResultsUsing(fn (string $search): array => self::siteOptions(search: $search, limit: 50))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::siteLabel((int) $value))
                ->default(fn (): ?int => self::defaultPreviewSite()?->getKey())
                ->live()
                ->required(),
            Select::make('page_id')
                ->label(__('capell-admin::form.page'))
                ->searchable()
                ->options(fn (Get $get): array => self::pageOptions((int) ($get('site_id') ?? 0), limit: 50))
                ->getSearchResultsUsing(fn (Get $get, string $search): array => self::pageOptions((int) ($get('site_id') ?? 0), search: $search, limit: 50))
                ->getOptionLabelUsing(fn (Get $get, mixed $value): ?string => self::pageLabel((int) $value, (int) ($get('site_id') ?? 0)))
                ->default(fn (): ?int => self::defaultPreviewPage()?->getKey())
                ->required(),
            Select::make('preset_key')
                ->label(__('capell-admin::theme-library.labels.presets'))
                ->options(fn (Theme $record): array => self::presetOptions($record))
                ->required(false),
        ];
    }

    /** @return array<int, mixed> */
    private static function applySchema(): array
    {
        return [
            Select::make('scope')
                ->label(__('capell-admin::theme-library.labels.activation_scope'))
                ->options(fn (): array => self::activationScopeOptions())
                ->default(fn (): string => self::currentActorCanApplyGlobally()
                    ? ThemeActivationScope::Global->value
                    : ThemeActivationScope::SelectedSites->value)
                ->helperText(__('capell-admin::theme-library.help.activation_scope'))
                ->live()
                ->required(),
            Select::make('site_ids')
                ->label(__('capell-admin::generic.sites'))
                ->multiple()
                ->searchable()
                ->options(fn (): array => self::siteOptions(limit: 50))
                ->getSearchResultsUsing(fn (string $search): array => self::siteOptions(search: $search, limit: 50))
                ->getOptionLabelsUsing(fn (array $values): array => self::siteLabels($values))
                ->helperText(__('capell-admin::theme-library.help.selected_sites'))
                ->visible(fn (Get $get): bool => self::selectedSitesScope($get('scope')))
                ->required(fn (Get $get): bool => self::selectedSitesScope($get('scope'))),
        ];
    }

    private static function selectedSitesScope(mixed $scope): bool
    {
        return $scope instanceof ThemeActivationScope
            ? $scope === ThemeActivationScope::SelectedSites
            : $scope === ThemeActivationScope::SelectedSites->value;
    }

    /** @return array<int, string> */
    private static function siteOptions(?string $search = null, int $limit = 50): array
    {
        $query = SiteScope::applyForCurrentActor(Site::query()->ordered(), 'id', denyWhenMissingActor: true);

        if (is_string($search) && $search !== '') {
            $query->where('name', 'like', sprintf('%%%s%%', $search));
        }

        return $query
            ->limit($limit)
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    private static function pageOptions(int $siteId, ?string $search = null, int $limit = 50): array
    {
        $site = self::findScopedSite($siteId);

        if (! $site instanceof Site) {
            return [];
        }

        $query = Page::query()
            ->where('site_id', $site->getKey())
            ->defaultOrder();

        if (is_string($search) && $search !== '') {
            $query->where('name', 'like', sprintf('%%%s%%', $search));
        }

        return $query
            ->limit($limit)
            ->pluck('name', 'id')
            ->all();
    }

    private static function siteLabel(int $siteId): ?string
    {
        return self::findScopedSite($siteId)?->name;
    }

    /**
     * @param  array<int, mixed>  $siteIds
     * @return array<int, string>
     */
    private static function siteLabels(array $siteIds): array
    {
        $ids = collect($siteIds)
            ->filter(fn (mixed $siteId): bool => is_numeric($siteId))
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return SiteScope::applyForCurrentActor(Site::query()->whereKey($ids), 'id', denyWhenMissingActor: true)
            ->pluck('name', 'id')
            ->all();
    }

    private static function pageLabel(int $pageId, int $siteId): ?string
    {
        $site = self::findScopedSite($siteId);

        if (! $site instanceof Site) {
            return null;
        }

        return self::findScopedPage($pageId, $site)?->name;
    }

    /** @return array<string, string> */
    private static function presetOptions(Theme $theme): array
    {
        $registry = app()->bound(ThemeRegistry::class)
            ? resolve(ThemeRegistry::class)
            : null;

        if (! $registry instanceof ThemeRegistry || ! $registry->has($theme->key)) {
            return [];
        }

        return $registry->definition($theme->key)->presetOptions();
    }

    private static function defaultPreviewSite(): ?Site
    {
        $defaultSite = SiteScope::applyForCurrentActor(Site::query()->default(), 'id', denyWhenMissingActor: true)->first();

        if ($defaultSite instanceof Site) {
            return $defaultSite;
        }

        return SiteScope::applyForCurrentActor(Site::query()->ordered(), 'id', denyWhenMissingActor: true)->first();
    }

    private static function defaultPreviewPage(?Site $site = null): ?Page
    {
        $site ??= self::defaultPreviewSite();

        if (! $site instanceof Site) {
            return null;
        }

        /** @var Page|null $page */
        $page = Page::getSiteHomePage($site)
            ?? Page::query()
                ->where('site_id', $site->getKey())
                ->defaultOrder()
                ->first();

        return $page;
    }

    /**
     * @return array<string, string>
     */
    private static function activationScopeOptions(): array
    {
        $options = [
            ThemeActivationScope::SelectedSites->value => ThemeActivationScope::SelectedSites->getLabel(),
        ];

        if (self::currentActorCanApplyGlobally()) {
            return [
                ThemeActivationScope::Global->value => ThemeActivationScope::Global->getLabel(),
                ...$options,
            ];
        }

        return $options;
    }

    private static function currentActorCanApplyGlobally(): bool
    {
        $actor = auth()->user();

        return $actor instanceof Authenticatable && SiteScope::isGlobalActor($actor);
    }

    private static function findScopedSite(int $siteId): ?Site
    {
        if ($siteId <= 0) {
            return null;
        }

        return SiteScope::applyForCurrentActor(Site::query()->whereKey($siteId), 'id', denyWhenMissingActor: true)->first();
    }

    private static function findScopedPage(int $pageId, Site $site): ?Page
    {
        if ($pageId <= 0) {
            return null;
        }

        return Page::query()
            ->whereKey($pageId)
            ->where('site_id', $site->getKey())
            ->first();
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     *
     * @throws ValidationException
     */
    private static function authorizedSiteIds(array $siteIds): array
    {
        $authorizedSiteIds = array_values(SiteScope::applyForCurrentActor(Site::query()->whereKey($siteIds), 'id', denyWhenMissingActor: true)
            ->pluck('id')
            ->map(fn (int $siteId): int => $siteId)
            ->values()
            ->all());

        sort($siteIds);
        sort($authorizedSiteIds);

        if ($siteIds !== $authorizedSiteIds) {
            throw ValidationException::withMessages([
                'site_ids' => __('capell-admin::theme-library.validation.selected_sites_forbidden'),
            ]);
        }

        return $authorizedSiteIds;
    }
}
