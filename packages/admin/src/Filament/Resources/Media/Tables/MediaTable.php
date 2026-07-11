<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Media\Tables;

use Capell\Admin\Actions\ReplaceMediaFileAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\NameColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Filament\Resources\Themes\Tables\ThemesTable;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\MediaScope;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Theme;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

class MediaTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns(static::getTableColumns())
            ->filters([
                SelectFilter::make('collection_name')
                    ->label(__('capell-admin::table.collection'))
                    ->options(
                        fn (): array => MediaScope::applyForCurrentActor(Media::query())
                            ->select('collection_name')
                            ->distinct()
                            ->orderBy('collection_name')
                            ->pluck('collection_name', 'collection_name')
                            ->all(),
                    ),
                SelectFilter::make('mime_group')
                    ->label(__('capell-admin::table.file_type'))
                    ->options(self::mimeGroups())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        if ($value === 'application/pdf') {
                            return $query->where('mime_type', $value);
                        }

                        return $query->where('mime_type', 'like', $value . '/%');
                    }),
                SelectFilter::make('model_type')
                    ->label(__('capell-admin::table.record_type'))
                    ->options(fn (): array => collect(Relation::morphMap())
                        ->filter(fn (string $class, string $alias): bool => class_exists($class))
                        ->mapWithKeys(fn (string $class, string $alias): array => [$alias => Str::headline(class_basename($class))])
                        ->sort()
                        ->all())
                    ->searchable(),
                TrashedFilter::make()
                    ->native(false),
            ])
            ->recordUrl(fn (Media $record): string => AdminSurfaceLookup::resource(ResourceEnum::Media)::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('capell-admin::media.empty_heading'))
            ->emptyStateDescription(__('capell-admin::media.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedPhoto)
            ->recordActions([
                EditAction::make()
                    ->label(__('capell-admin::media.manage_media')),
                ActionGroup::make([
                    Action::make('open-owner')
                        ->label(__('capell-admin::media.open_owner'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->color('gray')
                        ->url(fn (Media $record): ?string => self::getOwnerUrl($record))
                        ->visible(fn (Media $record): bool => self::getOwnerUrl($record) !== null),
                    self::editThemeOwnerAction(),
                    self::editBlueprintOwnerAction(),
                    self::editLanguageOwnerAction(),
                    self::editPageUrlOwnerAction(),
                    self::editRedirectOwnerAction(),
                ])
                    ->color('gray'),
                Action::make('replace-file')
                    ->label(__('capell-admin::media.replace_file'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->color('gray')
                    ->authorize(fn (Media $record): bool => Gate::allows('update', $record))
                    ->schema([
                        FileUpload::make('replacement')
                            ->label(__('capell-admin::media.replacement_file'))
                            ->required(),
                    ])
                    ->action(function (Media $record, array $data): void {
                        // Filament FileUpload stores the file to the local disk and
                        // returns a disk-relative path. Convert it to an absolute path.
                        $diskRelativePath = is_array($data['replacement'] ?? null)
                            ? (string) array_values($data['replacement'])[0]
                            : (string) ($data['replacement'] ?? '');

                        if ($diskRelativePath === '') {
                            return;
                        }

                        $absolutePath = Storage::disk('local')->path($diskRelativePath);

                        ReplaceMediaFileAction::run($record, $absolutePath);

                        Notification::make()
                            ->title(__('capell-admin::media.replace_file_success'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getOwnerUrl(Media $media): ?string
    {
        $modelClass = Relation::getMorphedModel($media->model_type) ?? $media->model_type;

        if (! class_exists($modelClass)) {
            return null;
        }

        $model = $media->model;

        if ($model === null) {
            return null;
        }

        $modelType = class_basename($model);

        // Try to get resource by model type first
        $resource = AdminSurfaceLookup::resourceIfRegistered($modelType);

        // If model is a page variation, try Page resource with lowercase model name
        if ($resource === null && $model instanceof Pageable) {
            $resource = AdminSurfaceLookup::resourceIfRegistered('Page', Str::lower($modelType));
        }

        if ($resource === null) {
            return null;
        }

        if (! self::canEditResourceRecord($resource, $model)) {
            return null;
        }

        try {
            return $resource::getUrl('edit', ['record' => $model->getKey()]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            ImageColumn::make('original_url')
                ->label(__('capell-admin::table.image'))
                ->circular()
                ->extraImgAttributes(['loading' => 'lazy'])
                ->imageSize(36)
                ->toggleable(),
            NameColumn::make('file_name')
                ->label(__('capell-admin::table.name'))
                ->searchable()
                ->copyable()
                ->copyMessage(__('capell-admin::media.file_name_copied')),
            TextColumn::make('collection_name')
                ->label(__('capell-admin::table.collection'))
                ->sortable()
                ->searchable()
                ->toggleable(),
            TextColumn::make('disk')
                ->label(__('capell-admin::table.storage_disk'))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('mime_type')
                ->label(__('capell-admin::table.file_type'))
                ->sortable()
                ->searchable()
                ->toggleable(),
            TextColumn::make('size')
                ->label(__('capell-admin::table.size'))
                ->alignRight()
                ->numeric()
                ->sortable()
                ->formatStateUsing(fn (int|string|null $state): ?string => is_numeric($state) ? Number::fileSize((int) $state) : null)
                ->toggleable(),
            TextColumn::make('model_type')
                ->label(__('capell-admin::table.type'))
                ->formatStateUsing(fn (?string $state): ?string => $state !== null ? Str::of(class_basename($state))->headline()->toString() : null)
                ->toggleable(isToggledHiddenByDefault: true)
                ->toggleable(),
            TextColumn::make('model_id')
                ->label(__('capell-admin::table.record'))
                ->toggleable(isToggledHiddenByDefault: true)
                ->alignCenter()
                ->toggleable(),
            TextColumn::make('owner_label')
                ->label(__('capell-admin::table.owner'))
                ->getStateUsing(fn (Media $record): ?string => self::getOwnerLabel($record))
                ->toggleable(),
            TextColumn::make('usage_count')
                ->label(__('capell-admin::table.usage'))
                ->badge()
                ->color(fn (int $state): string => $state === 0 ? 'gray' : ($state > 5 ? 'warning' : 'success'))
                ->getStateUsing(fn (Media $record): int => $record->usage_count)
                ->toggleable(),
            DateColumn::make('created_at'),
        ];
    }

    protected static function getOwnerLabel(Media $media): ?string
    {
        $model = $media->model;

        if ($model === null) {
            return null;
        }

        $type = class_basename($model::class);

        $name = $model->getAttribute('name') !== null && $model->getAttribute('name') !== ''
            ? (string) $model->getAttribute('name')
            : ($model->getAttribute('title') !== null && $model->getAttribute('title') !== '' ? (string) $model->getAttribute('title') : '#' . $model->getKey());

        return sprintf('%s — %s', Str::headline($type), $name);
    }

    private static function editThemeOwnerAction(): Action
    {
        return Action::make('edit-owner-theme')
            ->label(__('capell-admin::button.edit_theme'))
            ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Theme->permission('update')) === true)
            ->schema(fn (Schema $schema, Media $record): Schema => ThemeResource::form($schema->record($record->model)))
            ->fillForm(fn (Media $record): array => $record->model instanceof Theme ? $record->model->attributesToArray() : [])
            ->modalHeading(fn (Media $record): string => $record->model instanceof Theme ? $record->model->name : '')
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->mutateFormDataUsing(fn (array $data, Media $record): array => $record->model instanceof Theme ? ThemesTable::editorRecordData($record->model, $data) : $data)
            ->action(fn (Media $record, array $data): mixed => $record->model instanceof Theme ? $record->model->update($data) : null)
            ->hidden(fn (Media $record): bool => ! $record->model instanceof Theme || $record->model->trashed());
    }

    private static function editBlueprintOwnerAction(): Action
    {
        return Action::make('edit-owner-blueprint')
            ->label(__('capell-admin::button.edit_blueprint'))
            ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Blueprint->permission('update')) === true)
            ->schema(fn (Schema $schema, Media $record): Schema => BlueprintResource::form($schema->record($record->model)))
            ->fillForm(fn (Media $record): array => $record->model instanceof Blueprint ? $record->model->attributesToArray() : [])
            ->modalHeading(fn (Media $record): string => $record->model instanceof Blueprint ? $record->model->name : '')
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->hidden(fn (Media $record): bool => ! $record->model instanceof Blueprint || $record->model->trashed())
            ->mutateFormDataUsing(function (array $data, Media $record): array {
                if (! $record->model instanceof Blueprint) {
                    return $data;
                }

                $data['type'] = $record->model->type->getLabel();

                return $data;
            })
            ->action(function (Media $media, array $data): void {
                if (! $media->model instanceof Blueprint) {
                    return;
                }

                $record = $media->model;
                $roleRestrictions = $data['admin']['role_restrictions'] ?? null;
                unset($data['admin']['role_restrictions']);

                $record->update($data);

                if (auth()->user()?->can('manageRestrictions', Page::class) !== true) {
                    return;
                }

                if (is_array($roleRestrictions)) {
                    $record->syncRoleRestrictions(array_values(array_map(intval(...), $roleRestrictions)));
                }
            });
    }

    private static function editLanguageOwnerAction(): Action
    {
        return Action::make('edit-owner-language')
            ->label(__('filament-actions::edit.single.label'))
            ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Language->permission('update')) === true)
            ->schema(fn (Schema $schema, Media $record): Schema => LanguageResource::form($schema->record($record->model)))
            ->fillForm(fn (Media $record): array => $record->model instanceof Language ? $record->model->attributesToArray() : [])
            ->modalHeading(fn (Media $record): string => $record->model instanceof Language ? $record->model->name : '')
            ->action(fn (Media $record, array $data): mixed => $record->model instanceof Language ? $record->model->update($data) : null)
            ->hidden(fn (Media $record): bool => ! $record->model instanceof Language || $record->model->trashed());
    }

    private static function editPageUrlOwnerAction(): Action
    {
        return Action::make('edit-owner-page-url')
            ->label(__('capell-admin::generic.edit_page_url'))
            ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::PageUrl->permission('update')) === true)
            ->schema(fn (Schema $schema, Media $record): Schema => PageUrlResource::form($schema->record($record->model)))
            ->fillForm(fn (Media $record): array => $record->model instanceof PageUrl ? $record->model->attributesToArray() : [])
            ->modalHeading(fn (Media $record): string => $record->model instanceof PageUrl ? $record->model->url : '')
            ->action(fn (Media $record, array $data): mixed => $record->model instanceof PageUrl ? $record->model->update($data) : null)
            ->hidden(fn (Media $record): bool => ! $record->model instanceof PageUrl || $record->model->trashed() || $record->model->type === UrlTypeEnum::Redirect);
    }

    private static function editRedirectOwnerAction(): Action
    {
        return Action::make('edit-owner-redirect')
            ->label(__('capell-admin::generic.edit_redirect'))
            ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Redirect->permission('update')) === true)
            ->schema(fn (Schema $schema, Media $record): Schema => RedirectResource::form($schema->record($record->model)))
            ->fillForm(fn (Media $record): array => $record->model instanceof PageUrl ? $record->model->attributesToArray() : [])
            ->modalHeading(fn (Media $record): string => $record->model instanceof PageUrl ? $record->model->url : '')
            ->action(fn (Media $record, array $data): mixed => $record->model instanceof PageUrl ? $record->model->update($data) : null)
            ->hidden(fn (Media $record): bool => ! $record->model instanceof PageUrl || $record->model->trashed() || $record->model->type !== UrlTypeEnum::Redirect || ! $record->model->is_manual);
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private static function canEditResourceRecord(string $resource, Model $model): bool
    {
        try {
            return $resource::hasPage('edit') && $resource::canEdit($model);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    private static function mimeGroups(): array
    {
        return [
            'image' => __('capell-admin::media.mime_groups.image'),
            'video' => __('capell-admin::media.mime_groups.video'),
            'audio' => __('capell-admin::media.mime_groups.audio'),
            'application/pdf' => __('capell-admin::media.mime_groups.pdf'),
            'application' => __('capell-admin::media.mime_groups.document'),
            'text' => __('capell-admin::media.mime_groups.text'),
        ];
    }
}
