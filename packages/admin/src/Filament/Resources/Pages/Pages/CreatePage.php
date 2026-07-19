<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Pages;

use Capell\Admin\Actions\BuildDefaultTranslationsAction;
use Capell\Admin\Actions\Pages\SavePageAuthoringAction;
use Capell\Admin\Actions\Pages\ValidatePageAuthoringAction;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Data\Pages\PageAuthoringInputData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Override;

/**
 * @property Page $record
 */
class CreatePage extends CreateRecord implements HasPageResource
{
    use HasConfigurableFormActionPosition;

    #[Url]
    public ?string $type = null;

    protected bool $createdAsDraft = false;

    /** @return class-string<PageResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<PageResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Page);

        return $resource;
    }

    public function createAsDraft(): void
    {
        $this->createdAsDraft = true;

        try {
            $this->create(another: false);
        } finally {
            $this->createdAsDraft = false;
        }
    }

    #[Override]
    public function mount(?Page $record = null): void
    {
        parent::mount();

        if (Language::query()->count() === 0) {
            $this->redirect(LanguageResource::getUrl());

            return;
        }

        if (Site::query()->count() === 0) {
            $this->redirect(SiteResource::getUrl('create'));
        }
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        $resource = static::getResource();

        return $resource::configuredForm($schema, ConfiguratorContextData::forCreate(
            ConfiguratorTypeEnum::Page,
            $this->type,
            $resource::getResourceName(),
        ));
    }

    protected function getPositionedFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAsDraftFormAction(),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    /** @return list<Action> */
    protected function getPositionedHeaderFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->submit(null)
                ->action(function (): void {
                    $this->create();
                }),
            $this->getCreateAsDraftFormAction(),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreateAsDraftFormAction(): Action
    {
        return Action::make('createAsDraft')
            ->label(__('capell-admin::button.save_as_draft'))
            ->tooltip(__('capell-admin::button.save_as_draft_tooltip'))
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->action('createAsDraft');
    }

    #[Override]
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('capell-admin::button.save_and_publish'));
    }

    #[Override]
    protected function getCreatedNotificationTitle(): ?string
    {
        if ($this->createdAsDraft) {
            return __('capell-admin::message.saved_as_draft');
        }

        return parent::getCreatedNotificationTitle();
    }

    protected function beforeFill(): void
    {
        /** @var class-string<Site> $model */
        $model = Site::class;

        $this->data['site_id'] ??= request('site_id')
            ?? $model::getDefault()?->id;

        $siteId = $this->data['site_id'] ?? null;
        $translations = $this->data['translations'] ?? null;

        if (
            ($translations === null || $translations === [])
            && $siteId !== null && $siteId !== ''
        ) {
            $this->data['translations'] = BuildDefaultTranslationsAction::run($siteId);
        }
    }

    // Ideally wanted to do this beforeSave but the record instance is updated before then.
    protected function afterValidate(): void
    {
        ValidatePageAuthoringAction::run(
            formData: is_array($this->data) ? $this->data : [],
            operation: $this->createdAsDraft ? 'create-draft' : 'create',
        );

        $parentId = $this->data['parent_id'] ?? null;
        $translations = $this->data['translations'] ?? null;

        // Check all description languages exist in parent
        if (
            $parentId !== null && $parentId !== ''
            && is_array($translations) && $translations !== []
        ) {
            /** @var class-string<Page> $model */
            $model = Page::class;

            /** @var ?Page $parent */
            $parent = $model::with(['blueprint', 'translations'])->firstWhere(
                'id',
                $parentId,
            );

            if ($parent === null) {
                return;
            }

            $langIds = $parent->translations->pluck('language_id')->toArray();

            foreach ($translations as $translation) {
                if (! isset($translation['language_id'])) {
                    continue;
                }

                if (in_array($translation['language_id'], $langIds, true)) {
                    continue;
                }

                /** @var class-string<Language> $model */
                $model = Language::class;

                $language = $model::query()->find($translation['language_id']);

                if (! $language instanceof Language) {
                    continue;
                }

                Notification::make('page_language_parent')
                    ->warning()
                    ->title(__('capell-admin::message.page_language_parent', ['name' => $language->name]))
                    ->body(__('capell-admin::message.page_language_parent_info'))
                    ->actions([
                        Action::make('edit')
                            ->button()
                            ->label(__('capell-admin::generic.edit') . ' ' . Str::limit($parent->name, 30))
                            ->url(GetEditPageResourceUrlAction::run($parent)),
                    ])
                    ->send();

                $this->halt();
            }
        }
    }

    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);

        $formData = is_array($this->data) ? $this->data : [];

        static::getResource()::mutateFormDataBeforeCreate($data, $formData);

        /** @var class-string<Site> $model */
        $model = Site::class;

        $data['site_id'] ??= request('site_id')
            ?? $model::getDefault()?->id;

        if ($this->createdAsDraft) {
            // Sentinel: a far-future visible_from means "draft / not yet published".
            // pages.visible_from is DATETIME (not TIMESTAMP) so values beyond 2038 are safe.
            // Do NOT change this back to a TIMESTAMP column or MySQL will reject the insert.
            $data['visible_from'] = PagePublishSentinel::draftValue();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        return PublicationDateGuard::allow(
            fn (): Model => parent::handleRecordCreation($data),
        );
    }

    protected function afterCreate(): void
    {
        /** @var Pageable<Model> $page */
        $page = $this->record;

        SavePageAuthoringAction::run(new PageAuthoringInputData(
            page: $page,
            formData: is_array($this->data) ? $this->data : [],
            notifyCache: true,
        ));
    }
}
