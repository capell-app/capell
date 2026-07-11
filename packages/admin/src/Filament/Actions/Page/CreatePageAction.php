<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Page;

use Capell\Admin\Actions\Pages\ValidatePageAuthoringAction;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Site;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class CreatePageAction extends CreateAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->modalHeading(
                fn (self $action): string => __(
                    'capell-admin::button.create_type',
                    ['type' => $action->getResource()::getModelLabel()],
                ),
            )
            ->modalSubmitActionLabel(__('capell-admin::button.save_and_publish'))
            ->schema(function (Schema $schema, self $action): Schema {
                /** @var class-string<PageResource> $resource */
                $resource = $action->getResource();
                $arguments = $action->getArguments();
                $type = $arguments['type'] ?? null;

                return $resource::configuredForm($schema->operation('createOption'), ConfiguratorContextData::forCreate(
                    ConfiguratorTypeEnum::Page,
                    is_string($type) ? $type : null,
                    $resource::getResourceName(),
                ));
            })
            ->extraModalFooterActions(fn (self $action): array => [
                $action->makeModalSubmitAction('saveAsDraft', arguments: ['draft' => true])
                    ->label(__('capell-admin::button.save_as_draft'))
                    ->tooltip(__('capell-admin::button.save_as_draft_tooltip'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray'),
            ])
            ->successNotification(function (Model $record, self $action): Notification {
                $arguments = $action->getArguments();

                if (($arguments['draft'] ?? false) === true) {
                    return Notification::make()
                        ->title(__('capell-admin::message.saved_as_draft'))
                        ->success();
                }

                return Notification::make()
                    ->title(__('filament-actions::create.single.notifications.created.title'))
                    ->success();
            })
            ->successRedirectUrl(fn (Pageable $record): ?string => GetEditPageResourceUrlAction::run($record))
            ->preserveFormDataWhenCreatingAnother(
                /**
                 * @param  array<string, mixed>  $data
                 * @return array<string, mixed>
                 */
                function (self $action, array $data): array {
                    /** @var class-string<PageResource> $resource */
                    $resource = $action->getResource();

                    $group = $resource::getResourceName();

                    return $this->defaultFormData($group, []);
                },
            )
            ->mountUsing(function (Schema $schema, self $action): void {
                /** @var class-string<PageResource> $resource */
                $resource = $action->getResource();

                $group = $resource::getResourceName();

                $data = $this->defaultFormData($group, $this->arrayState($schema->getRawState()));

                $schema->fill($data);
            })
            ->beforeFormValidated(function (Page $livewire, self $action): void {
                $schemaName = $livewire->getMountedActionSchemaName();

                if ($schemaName === null) {
                    return;
                }

                $schema = $livewire->getSchema($schemaName);

                if (! $schema instanceof Schema) {
                    return;
                }

                /** @var class-string<PageResource> $resource */
                $resource = $action->getResource();

                $group = $resource::getResourceName();
                $formData = $this->arrayState($schema->getRawState());
                $data = $action->mutateFormDataBeforeCreate($group, $formData, $formData);

                $action->data($data, shouldMutate: false);
            })
            ->before(function (self $action): void {
                $formData = $action->getRawData() !== [] ? $action->getRawData() : $action->getData();

                ValidatePageAuthoringAction::run(
                    formData: $formData,
                    page: null,
                    operation: ($action->getArguments()['draft'] ?? false) === true ? 'modal-create-draft' : 'modal-create',
                );
            })
            ->after(function (Pageable $record, self $action): void {
                PageSavedAction::run($record, $action->getRawData());
            })
            ->using(
                /**
                 * @param  array<string, mixed>  $data
                 */
                function (array $data, Page $livewire, self $action): Model {
                    $schemaName = $livewire->getMountedActionSchemaName();

                    throw_if($schemaName === null, RuntimeException::class, 'Unable to resolve mounted action schema.');

                    $schema = $livewire->getSchema($schemaName);

                    throw_unless($schema instanceof Schema, RuntimeException::class, 'Unable to resolve mounted action schema.');

                    $formData = $this->arrayState($schema->getRawState());

                    /** @var class-string<PageResource> $resource */
                    $resource = $action->getResource();
                    $group = $resource::getResourceName();

                    $data = $action->mutateFormDataBeforeCreate($group, $data, $formData);

                    if (($action->getArguments()['draft'] ?? false) === true) {
                        // Sentinel: a far-future visible_from means "draft / not yet published".
                        // pages.visible_from is DATETIME (not TIMESTAMP) so values beyond 2038 are safe.
                        // Do NOT change this back to a TIMESTAMP column or MySQL will reject the insert.
                        $data['visible_from'] = now()->addYears(100);
                    }

                    return self::saveActionUsing($data, $livewire);
                },
            );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(string $group, array $data, array $formData): array
    {
        if (! isset($data['name']) || $data['name'] === '') {
            $translations = $formData['translations'] ?? [];
            $firstTranslation = is_array($translations) ? reset($translations) : [];

            $data['name'] = is_array($firstTranslation) ? (string) ($firstTranslation['title'] ?? '') : '';
        }

        if (! array_key_exists('site_id', $data) || $data['site_id'] === null) {
            /** @var class-string<Site> $model */
            $model = Site::class;

            $data['site_id'] = $model::query()->with('languages')->default()->value('id');
        }

        $this->getPageResource($group)::mutateFormDataBeforeCreate($data, $formData);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    protected function defaultFormData(string $group, array $formData): array
    {
        $data = [];

        /** @var class-string<Site> $model */
        $model = Site::class;

        $site = $model::query()->with('languages')->default()->first();

        $this->getPageResource($group)::mutateFormDataBeforeCreate($data, $formData);

        if ($site !== null) {
            $data['site_id'] = $site->id;

            if (! isset($data['translations']) || (is_array($data['translations']) && $data['translations'] === [])) {
                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
                $slug = $name !== '' ? str($name)->slug()->toString() : '';

                $data['translations'] = [
                    (string) Str::uuid() => [
                        'language_id' => $site->language_id,
                        'title' => $name,
                        'meta' => ['slug' => $slug],
                    ],
                ];
            }
        }

        return $this->mutateFormData($data);
    }

    /**
     * @return class-string<PageResource>
     */
    private function getPageResource(string $group): string
    {
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Page, $group);

        throw_if(! is_a($resource, PageResource::class, true), RuntimeException::class, 'Resolved resource is not a PageResource for group: ' . $group);

        return $resource;
    }
}
