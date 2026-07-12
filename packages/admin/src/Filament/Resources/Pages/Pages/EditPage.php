<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Pages;

use Capell\Admin\Actions\MutateContentPresenterAction;
use Capell\Admin\Actions\Pages\SavePageAuthoringAction;
use Capell\Admin\Actions\Pages\ValidatePageAuthoringAction;
use Capell\Admin\Contracts\Extenders\PageEditExtender;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Data\Pages\PageAuthoringInputData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\ListenerEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Actions\Page\DeletePageAction;
use Capell\Admin\Filament\Actions\Page\FrontendResourceDiagnosticsHeaderAction;
use Capell\Admin\Filament\Actions\Page\FrontendSourceMapHeaderAction;
use Capell\Admin\Filament\Actions\Page\ReplicatePageAction;
use Capell\Admin\Filament\Concerns\HasAncestorBreadcrumbs;
use Capell\Admin\Filament\Concerns\HasBlueprintRelationManagers;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Concerns\HasCreateActionOnEditPage;
use Capell\Admin\Filament\Concerns\HasDynamicEventListeners;
use Capell\Admin\Filament\Concerns\HasExtensibleRecordHeading;
use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Pages\Actions\PreviewDraftPageHeaderAction;
use Capell\Admin\Filament\Resources\Pages\Actions\RevisionsHeaderAction;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Actions\ContentLocks\AcquireContentLockAction;
use Capell\Core\Actions\ContentLocks\FindConflictingContentLockAction;
use Capell\Core\Actions\ContentLocks\ForceContentLockAction;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\GetResourceFromBlueprintAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Override;

/**
 * @property Page $record
 */
#[On('$refresh')]
class EditPage extends EditRecord implements HasPageResource, ValidatesDelete
{
    use HasAncestorBreadcrumbs;
    use HasBlueprintRelationManagers;
    use HasConfigurableFormActionPosition;
    use HasCreateActionOnEditPage;
    use HasDynamicEventListeners;
    use HasExtensibleRecordHeading;
    use InteractsWithFormActions {
        HasConfigurableFormActionPosition::getFormActions insteadof InteractsWithFormActions;
    }
    use PageValidation;

    /** @var array<int, string> */
    #[Locked]
    public array $urlChanges = [];

    protected bool $savingAsDraft = false;

    /** @var list<string> */
    protected $listeners = [
        'auditRestored',
    ];

    /** @return class-string<PageResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<PageResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Page);

        return $resource;
    }

    #[Override]
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->acquirePageLock();
    }

    public function hasPageHierarchy(): bool
    {
        $resource = static::getResource();

        return $resource::hasPageHierarchy();
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        $resource = static::getResource();

        return $resource::configuredForm($schema, ConfiguratorContextData::forEdit(
            ConfiguratorTypeEnum::Page,
            $resource::getResourceName(),
        ));
    }

    #[Override]
    public function content(Schema $schema): Schema
    {
        $components = [
            $this->contentLockHeartbeatComponent(),
        ];

        if ($this->hasCombinedRelationManagerTabsWithContent()) {
            $components[] = $this->getRelationManagersContentComponent();
        } else {
            $components[] = $this->getFormContentComponent();
            $components[] = $this->getRelationManagersContentComponent();
        }

        return $schema->components($components);
    }

    #[Override]
    public function getRelationManagers(): array
    {
        $managers = $this->getTypeRelationManagers();

        return array_filter(
            $managers,
            function (string|RelationGroup|RelationManagerConfiguration $manager): bool {
                if ($manager instanceof RelationGroup) {
                    return (bool) count($manager->ownerRecord($this->getRecord())->pageClass(static::class)->getManagers());
                }

                if (is_string($manager) && ! is_subclass_of($manager, RelationManager::class)) {
                    return false;
                }

                return $this->normalizeRelationManagerClass($manager)::canViewForRecord($this->getRecord(), static::class);
            },
        );
    }

    /**
     * @param  array<int, string>  $urls
     */
    #[On('add-url-redirects')]
    public function addUrlRedirects(array $urls): void
    {
        Gate::authorize('create', PageUrl::class);

        $redirectUrls = collect($urls)
            ->mapWithKeys(fn (string $url, int $languageId): array => [$languageId => $url])
            ->filter(fn (string $url, int $languageId): bool => isset($this->urlChanges[$languageId])
                && hash_equals($this->urlChanges[$languageId], $url))
            ->all();

        if ($redirectUrls === []) {
            return;
        }

        /** @var class-string<Language> $model */
        $model = Language::class;

        $languages = $model::query()->whereIn('id', array_keys($redirectUrls))->get();

        throw_if($languages->isEmpty(), InvalidArgumentException::class, 'No valid languages found for URL redirects.');

        $languages->each(function (Language $language) use ($redirectUrls): void {
            resolve(RedirectUrlRecorder::class)->record($this->record, $language, $redirectUrls[$language->id]);
        });

        Notification::make('url-redirects-added')
            ->title(__('capell-admin::message.url_redirects_added'))
            ->success()
            ->send();

        $this->dispatch('close-notification', id: 'url-changes');

        $this->dispatch('refresh-relation')->to(UrlsRelationManager::class);
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return new HtmlString(
            __('capell-admin::heading.edit_page_record', [
                'name' => Str::limit($this->recordTitleText(), 40) . ' - ' . $this->record->site->name,
            ]),
        );
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        // Site, Page type, and Layout are editable in the form (Settings schema),
        // so the header only surfaces the page URL — rendered as a clickable link
        // to the live page when one is resolvable.
        $pageUrl = $this->record->pageUrls->first();
        $fullUrl = $pageUrl instanceof PageUrl ? PageUrlPresenter::fullUrl($pageUrl) : null;

        return new HtmlString(sprintf(
            '<span class="flex flex-wrap gap-1.5">%s</span>',
            $this->subheadingMetaChipLink(
                (string) __('capell-admin::table.url'),
                $this->pageDisplayUrl(),
                $fullUrl,
            ),
        ));
    }

    #[On('page-layout-changed')]
    public function layoutUpdated(int $id): void
    {
        $this->data['layout_id'] = $id;

        $this->record->layout_id = $id;

        $this->record->load('layout');
    }

    #[On('page-type-content-structure-updated')]
    public function pageTypeContentStructureUpdated(ContentStructure $contentStructure): void
    {
        // The content_structure_override save below records an event-sourced
        // revision (via the recording bridge) before the destructive translation
        // mutation, so the editor can roll back from the page history timeline.
        Notification::make('content-structure-updated')
            ->title(__('capell-admin::message.content_structure_updated'))
            ->body(__('capell-admin::message.content_structure_updated_body'))
            ->warning()
            ->persistent()
            ->send();

        CapellCoreHelper::clearType($this->record->blueprint->id);

        // Persist the mode as a per-page override (was previously a blueprint
        // mutation, which cloned the blueprint when shared and accumulated
        // orphans). The Page accessor prefers the override over the blueprint.
        $this->record->forceFill([
            'content_structure_override' => $contentStructure->value,
        ])->save();
        $this->record->refresh();

        $translations = $this->data['translations'] ?? [];

        $persistedTranslations = $this->record->translations;

        foreach ($translations as $index => $translationData) {
            $this->data['translations'][$index]['content'] = MutateContentPresenterAction::run(
                $this->resolveTranslationContentForStructureUpdate($index, $translationData, $persistedTranslations),
                $contentStructure,
                force: true,
            );
        }
    }

    public function saveAsDraft(): void
    {
        $handler = $this->draftHandler();

        if ($handler !== null) {
            $this->callDraftHandler($handler, 'saveAsDraft', $this);

            return;
        }

        $this->savingAsDraft = true;

        try {
            $this->save(shouldRedirect: false);
        } finally {
            $this->savingAsDraft = false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAsDraftWithLocation(array $data): void
    {
        $handler = $this->draftHandler();

        if ($handler !== null) {
            $this->callDraftHandler($handler, 'saveAsDraftWithLocation', $this, $data);
        }
    }

    public function deletePageDraft(int $draftId): void
    {
        $handler = $this->draftHandler();

        if ($handler !== null) {
            $this->callDraftHandler($handler, 'deletePageDraft', $this, $draftId);
        }
    }

    public function redirectToLive(): void
    {
        $handler = $this->draftHandler();

        if ($handler !== null) {
            $this->callDraftHandler($handler, 'redirectToLive', $this);
        }
    }

    /**
     * Remove aggregate `*_count` attributes loaded by resolveRecord via
     * loadCount(). They aren't real columns; the copy-on-write replicate()
     * inside save() would otherwise try to persist them.
     */
    public function stripCountAttributes(Page $record): void
    {
        $attributes = $record->getAttributes();

        foreach (array_keys($attributes) as $attribute) {
            if (str_ends_with($attribute, '_count')) {
                $record->offsetUnset($attribute);
            }
        }
    }

    protected function afterSave(): void
    {
        /** @var Pageable<Model> $page */
        $page = $this->record;

        SavePageAuthoringAction::run(new PageAuthoringInputData(
            page: $page,
            formData: is_array($this->data) ? $this->data : [],
            previousUrls: $this->urlChanges,
            recordRedirects: true,
        ));

        $this->dispatch('refresh-alerts');

        $this->dispatch('refresh-seo-audit');

        $this->dispatch('refresh-relation')->to(UrlsRelationManager::class);

        $this->notifyUrlChanges();

        $this->notifyEditRecordHeadingSaved();

        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::AfterSave, $this);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);

        unset($data['translations']);

        // When "Save and Publish" is clicked on a pending (draft) page, clear the
        // far-future visible_from sentinel so the page becomes live immediately.
        // Skip this when saveAsDraft() is the caller — that path preserves draft state.
        if ($this->record->isPending() && ! $this->savingAsDraft) {
            $data['visible_from'] = null;
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $user = $this->currentUser();

        if ($user instanceof Authenticatable) {
            $conflictingLock = FindConflictingContentLockAction::run($this->record, $user);

            if ($conflictingLock !== null) {
                $this->notifyContentLockConflict($this->contentLockUser($conflictingLock), saveBlocked: true);
                $this->halt();

                return;
            }

            AcquireContentLockAction::run($this->record, $user);
        }

        $this->urlChanges = $this->getUpdatedUrlChanges();
    }

    protected function afterValidate(): void
    {
        ValidatePageAuthoringAction::run(
            formData: is_array($this->data) ? $this->data : [],
            page: $this->record,
            operation: 'save',
        );
    }

    // Ideally wanted to do this beforeSave but the relation records are updated before then.
    protected function beforeValidate(): void
    {
        if ($this->hasPageHierarchy() && ! $this->validateParentLanguages()) {
            $this->halt();
        }
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->getBaseHeaderActions();
    }

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getBaseHeaderActions(): array
    {
        $pipeline = resolve(AdminSchemaExtensionPipeline::class);

        $extenderActions = collect($pipeline->resourceHeaderActions(static::class))
            ->reject(fn (Action $action): bool => $this->isContentBlocksConversionHeaderAction($action))
            ->values();

        // The Sitemap action (contributed by the site-discovery package extender)
        // is relocated from the top-level header into the overflow menu to keep the
        // header tidy; it remains available on every page screen as before.
        $sitemapAction = $extenderActions->first(fn (Action $action): bool => $this->isSitemapHeaderAction($action));

        $topLevelExtenderActions = $extenderActions
            ->reject(fn (Action $action): bool => $this->isSitemapHeaderAction($action))
            ->values()
            ->all();

        return [
            ...$topLevelExtenderActions,
            ...$pipeline->pagePreviewActions(),
            PreviewDraftPageHeaderAction::make(),
            $this->takeOverContentLockAction(),
            RestoreAction::make()
                ->icon('heroicon-m-arrow-uturn-left'),
            $this->deletePageAction(),
            ForceDeleteAction::make()
                ->icon('heroicon-m-trash'),
            ActionGroup::make(array_values(array_filter([
                $sitemapAction,
                CreatePageAction::make()
                    ->redirectAfterCreate(),
                ReplicatePageAction::make(),
                FrontendResourceDiagnosticsHeaderAction::make(),
                FrontendSourceMapHeaderAction::make(),
                RevisionsHeaderAction::make(),
            ])))
                ->dropdownPlacement('bottom-end'),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getPositionedFormActions(): array
    {
        return array_values([
            $this->getSaveAndPublishFormAction(),
            ...$this->getPageEditExtenderFormActions(),
            $this->getCancelFormAction(),
        ]);
    }

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getPositionedHeaderFormActions(): array
    {
        return array_values([
            $this->getSaveAndPublishFormAction()
                ->submit(null)
                ->action(fn (): mixed => $this->save()),
            ...$this->getPageEditExtenderFormActions(),
            $this->getCancelFormAction(),
        ]);
    }

    protected function getSaveAndPublishFormAction(): Action
    {
        return $this->getSaveFormAction()
            ->label(fn (): string => $this->isLivePublishedRecord()
                ? __('capell-admin::button.save')
                : __('capell-admin::button.save_and_publish'));
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getPageEditExtenderFormActions(): array
    {
        return collect(app()->tagged(PageEditExtender::TAG))
            ->flatMap(fn (PageEditExtender $extender): array => $extender->getFormActions())
            ->all();
    }

    #[Override]
    protected function getHeaderWidgets(): array
    {
        return collect(app()->tagged(PageEditExtender::TAG))
            ->flatMap(fn (PageEditExtender $extender): array => $extender->getHeaderWidgets())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function getUpdatedUrlChanges(): array
    {
        $data = is_array($this->data) ? $this->data : [];

        if ($this->hasPageHierarchy() && $this->record->parent_id !== ($data['parent_id'] ?? null)) {
            return $this->record->pageUrls->pluck('url', 'language_id')->toArray();
        }

        $translations = collect(is_array($data['translations'] ?? null) ? $data['translations'] : []);

        $keyedTranslations = $translations->keyBy('language_id');

        return $this->record
            ->translations
            ->filter(function (Translation $translation) use ($keyedTranslations): bool {
                $existingTranslation = $keyedTranslations[$translation->language_id] ?? null;

                $slug = $existingTranslation['meta']['slug'] ?? null;

                return $existingTranslation === null || $slug !== $translation->slug;
            })
            ->mapWithKeys(
                function (Translation $translation): array {
                    $pageUrl = $translation->pageUrl;

                    return $pageUrl === null ? [] : [$translation->language_id => $pageUrl->url];
                },
            )
            ->all();
    }

    #[Override]
    protected function resolveRecord(int|string $key): Model
    {
        /** @var Page $record */
        $record = parent::resolveRecord($key);

        $record->load([
            'site' => fn (BuilderContract $query): BuilderContract => $query->withTrashed(),
            'layout',
            'blueprint',
            'pageUrls.siteDomain',
            'pageUrls.language',
        ])
            ->loadCount([
                'canonicalPages',
            ]);

        if ($this->hasPageHierarchy() && $record->parent_id !== null && $record->parent_id !== 0) {
            $record->load([
                'parent' => function (BuilderContract $query): BuilderContract {
                    if ($query instanceof Builder) {
                        foreach (app()->tagged(PageTableExtender::TAG) as $extender) {
                            if ($extender instanceof PageTableExtender) {
                                $query = $extender->modifyQuery($query);
                            }
                        }

                        $query->with(['blueprint', 'translations.language']);
                    }

                    return $query;
                },
            ])
                ->loadCount([
                    'children',
                    'siblings',
                ]);
        }

        return $record;
    }

    /**
     * @param  Page  $model
     */
    protected function selectChangerItemGroup(Model $model): ?string
    {
        return $model->site->name;
    }

    protected function selectChangerItemLabel(Page $model): HtmlString
    {
        $label = '';

        if ($model->ancestors->isNotEmpty()) {
            $label .= $model->ancestors->pluck('name')
                ->map(fn (string $name): string => e(Str::limit($name, 30)))
                ->implode(' &raquo; ')
                . ' &raquo; ';
        }

        return new HtmlString($label . e($model->name));
    }

    /**
     * @param  array<string, mixed>  $translationData
     * @param  Collection<int, Translation>  $persistedTranslations
     */
    private function resolveTranslationContentForStructureUpdate(int|string $index, array $translationData, Collection $persistedTranslations): mixed
    {
        $content = $translationData['content'] ?? null;

        if (! in_array($content, [null, '', []], true)) {
            return $content;
        }

        if (! is_string($index) || ! str_starts_with($index, 'record-')) {
            return $content;
        }

        $translationId = (int) Str::after($index, 'record-');

        if ($translationId <= 0) {
            return $content;
        }

        $persistedTranslation = $persistedTranslations->firstWhere('id', $translationId);

        if (! $persistedTranslation instanceof Translation) {
            return $content;
        }

        return $persistedTranslation->content;
    }

    private function subheadingMetaChipLink(string $label, string $value, ?string $url): string
    {
        if ($url === null) {
            return $this->subheadingMetaChip($label, $value);
        }

        return sprintf(
            '<a href="%s" class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-600 ring-1 ring-gray-950/5 hover:bg-gray-200 hover:text-primary-700 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-primary-300"><span class="font-medium text-gray-800 dark:text-gray-100">%s</span><span class="truncate">%s</span></a>',
            e($url),
            e($label),
            e($value),
        );
    }

    private function isContentBlocksConversionHeaderAction(Action $action): bool
    {
        if (in_array($action->getName(), [
            'convertContentToBlocks',
            'convertToContentBlocks',
            'convert_content_to_blocks',
            'convert-content-to-blocks',
        ], true)) {
            return true;
        }

        return $action->getLabel() === __('capell-admin::button.convert_to_content_blocks');
    }

    private function isSitemapHeaderAction(Action $action): bool
    {
        return $action->getName() === 'sitemap';
    }

    private function isLivePublishedRecord(): bool
    {
        return (int) ($this->record->getAttributes()['workspace_id'] ?? 0) === 0
            && ! $this->record->isPending()
            && ! $this->record->isExpired();
    }

    private function recordTitleText(): string
    {
        $title = $this->getRecordTitle();

        return $title instanceof Htmlable ? $title->toHtml() : $title;
    }

    private function pageDisplayUrl(): string
    {
        $pageUrl = $this->record->pageUrls->first();
        $displayUrl = $pageUrl instanceof PageUrl ? PageUrlPresenter::displayUrl($pageUrl) : '';

        return $displayUrl !== ''
            ? Str::limit($displayUrl, 72)
            : (string) __('capell-admin::table.page_health_missing_url');
    }

    private function subheadingMetaChip(string $label, string $value): string
    {
        return sprintf(
            '<span class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10"><span class="font-medium text-gray-800 dark:text-gray-100">%s</span><span class="truncate">%s</span></span>',
            e($label),
            e($value),
        );
    }

    private function acquirePageLock(): void
    {
        $user = $this->currentUser();

        if (! $user instanceof Authenticatable) {
            return;
        }

        $lock = AcquireContentLockAction::run($this->record, $user);

        if (! $lock->isOwnedBy($user)) {
            $this->notifyContentLockConflict($this->contentLockUser($lock));
        }
    }

    private function contentLockHeartbeatComponent(): View
    {
        return View::make('capell-admin::components.content-lock-heartbeat')
            ->viewData([
                'config' => [
                    'heartbeatUrl' => Route::has('capell-admin.api.pages.content-lock.heartbeat')
                        ? route('capell-admin.api.pages.content-lock.heartbeat', ['page' => $this->record])
                        : '',
                    'releaseUrl' => Route::has('capell-admin.api.pages.content-lock.release')
                        ? route('capell-admin.api.pages.content-lock.release', ['page' => $this->record])
                        : '',
                    'csrfToken' => csrf_token(),
                    'intervalMs' => 30000,
                ],
            ]);
    }

    private function takeOverContentLockAction(): Action
    {
        return Action::make('take-over-content-lock')
            ->label(__('capell-admin::button.take_over_content_lock'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::button.take_over_content_lock'))
            ->modalDescription(__('capell-admin::message.content_lock_takeover_confirmation'))
            ->visible(fn (): bool => $this->hasConflictingContentLock())
            ->action(function (): void {
                $user = $this->currentUser();

                if (! $user instanceof Authenticatable) {
                    return;
                }

                ForceContentLockAction::run($this->record, $user);

                Notification::make('content-lock-taken-over')
                    ->success()
                    ->title(__('capell-admin::message.content_lock_taken_over'))
                    ->send();
            });
    }

    private function hasConflictingContentLock(): bool
    {
        $user = $this->currentUser();

        return $user instanceof Authenticatable
            && FindConflictingContentLockAction::run($this->record, $user) instanceof ContentLock;
    }

    private function currentUser(): ?Authenticatable
    {
        $user = Auth::user();

        return $user instanceof Authenticatable ? $user : null;
    }

    private function contentLockUser(ContentLock $lock): ?Authenticatable
    {
        $user = $lock->user;

        return $user instanceof Authenticatable ? $user : null;
    }

    private function notifyContentLockConflict(?Authenticatable $user, bool $saveBlocked = false): void
    {
        Notification::make($saveBlocked ? 'content-lock-save-blocked' : 'content-lock-active')
            ->warning()
            ->title(__('capell-admin::message.content_lock_active', [
                'name' => $this->contentLockUserName($user),
            ]))
            ->body($saveBlocked ? (string) __('capell-admin::message.content_lock_save_blocked') : null)
            ->persistent()
            ->send();
    }

    private function contentLockUserName(?Authenticatable $user): string
    {
        if ($user instanceof Model) {
            $name = $user->getAttribute('name');

            if (is_string($name) && $name !== '') {
                return $name;
            }

            $email = $user->getAttribute('email');

            if (is_string($email) && $email !== '') {
                return $email;
            }
        }

        $identifier = $user?->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : (string) __('capell-admin::generic.another_user');
    }

    private function draftHandler(): ?object
    {
        return app()->bound('capell.workspace.page-draft-handler')
            ? resolve('capell.workspace.page-draft-handler')
            : null;
    }

    private function callDraftHandler(object $handler, string $method, mixed ...$arguments): void
    {
        $callback = [$handler, $method];

        if (! is_callable($callback)) {
            throw new InvalidArgumentException(sprintf('Page draft handler method %s is not callable.', $method));
        }

        $callback(...$arguments);
    }

    private function deletePageAction(): DeletePageAction
    {
        return DeletePageAction::make()
            ->label(fn (): string => __('capell-admin::button.delete'))
            ->hidden(fn (): bool => $this->record->trashed())
            ->successRedirectUrl(function (): ?string {
                $resource = GetResourceFromBlueprintAction::run($this->record->blueprint);
                if ($resource !== null) {
                    return $resource::getUrl();
                }

                return GetEditPageResourceUrlAction::run($this->record);
            })
            ->before(function (self $livewire, DeletePageAction $action): void {
                if (! $livewire->validateDelete($this->record)) {
                    $action->halt();
                }
            });
    }

    private function notifyUrlChanges(): void
    {
        if ($this->urlChanges === []) {
            return;
        }

        Notification::make('url-changes')
            ->info()
            ->title(__('capell-admin::message.url_changed'))
            ->body(
                function (): HtmlString {
                    /** @var class-string<Language> $model */
                    $model = Language::class;

                    $languages = $model::query()->whereIn('id', array_keys($this->urlChanges))->get()->keyBy('id');

                    return new HtmlString(
                        __('capell-admin::generic.url_changes_info') .
                        '<br />' .
                        collect($this->urlChanges)
                            ->map(function (string $url, int $langId) use ($languages): string {
                                $language = $languages->get($langId);

                                return sprintf(
                                    '• <strong>%s:</strong> %s',
                                    e($language instanceof Language ? $language->name : ''),
                                    e($url),
                                );
                            })
                            ->implode('<br />'),
                    );
                },
            )
            ->actions([
                $this->addUrlRedirectNotificationAction(),
            ])
            ->persistent()
            ->send();
    }

    private function addUrlRedirectNotificationAction(): Action
    {
        return Action::make('add-redirect')
            ->label(__('capell-admin::button.add_redirect'))
            ->requiresConfirmation()
            ->modalDescription(__('capell-admin::message.add_url_redirect_confirmation'))
            ->visible(fn (): bool => auth()->user()?->can('create', PageUrl::class) ?? false)
            ->button()
            ->icon('heroicon-o-plus')
            ->badge(fn (): int => count($this->urlChanges))
            ->dispatchTo(static::getName(), 'add-url-redirects', [$this->urlChanges]);
    }

    private function validateParentLanguages(): bool
    {
        $data = is_array($this->data) ? $this->data : [];
        $parentId = $data['parent_id'] ?? null;

        if ($this->record->parent_id === (int) $parentId) {
            return true;
        }

        $selectedLangIds = [];
        foreach ((array) ($data['translations'] ?? []) as $translation) {
            if (isset($translation['language_id'])) {
                $selectedLangIds[] = $translation['language_id'];
            }
        }

        $selectedLangIds = array_map(intval(...), $selectedLangIds);

        $parent = Page::query()
            ->withWhereHas('blueprint')
            ->withWhereHas('translations')
            ->firstWhere('id', $parentId);

        if ($parent === null) {
            return true;
        }

        $langIds = $parent->translations->pluck('language_id')->toArray();

        foreach ($selectedLangIds as $language_id) {
            if (in_array($language_id, $langIds, true)) {
                continue;
            }

            $language = Language::query()->find($language_id);
            $languageName = $language instanceof Language ? $language->name : '';

            Notification::make('page_language_parent')
                ->warning()
                ->title(__('capell-admin::message.page_language_parent', ['name' => $languageName]))
                ->body(__('capell-admin::message.page_language_parent_info'))
                ->actions([
                    Action::make('edit')
                        ->button()
                        ->label(__('capell-admin::generic.edit') . ' ' . Str::limit($parent->name, 30))
                        ->url(GetEditPageResourceUrlAction::run($parent)),
                ])
                ->send();

            return false;
        }

        return true;
    }
}
