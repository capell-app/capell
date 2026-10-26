<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Media\Pages;

use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Actions\Media\UploadSiteMediaAction;
use Capell\Admin\Data\Media\MediaUploadBatchData;
use Capell\Admin\Data\Media\MediaUploadFileData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Components\Forms\FileUpload;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Filament\RawState;
use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Override;
use Throwable;

class ListMedia extends ListRecords
{
    use HasImportExportHeaderActions;

    #[Locked]
    public ?string $uploadBatchId = null;

    private ?string $submissionBatchId = null;

    /** @return class-string<MediaResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<MediaResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Media);

        return $resource;
    }

    /** @return array<int, mixed> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return $this->prependImportHeaderAction([
            Action::make('upload-files')
                ->label(__('capell-admin::media.upload_files'))
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize(fn (): bool => static::getResource()::canCreate())
                ->beforeFormValidated(function (): void {
                    abort_unless(static::getResource()::canCreate(), 403);
                    $schemaName = $this->getMountedActionSchemaName();
                    $schema = $schemaName === null ? null : $this->getSchema($schemaName);
                    if (! $schema instanceof Schema) {
                        return;
                    }

                    try {
                        $data = RawState::array($schema->getRawState());
                        $site = $this->uploadSite($data);
                        $this->submissionBatchId = UploadSiteMediaAction::make()->prepareFormSubmission($site, $data['files'] ?? [])->id;
                    } catch (ValidationException $validationException) {
                        throw $this->actionValidationException($validationException->errors());
                    }
                })
                ->schema([
                    FileUpload::make('files')
                        ->preventFilePathTampering()
                        ->label(__('capell-admin::media.upload_files'))
                        ->multiple()
                        ->storeFileNamesIn('original_file_names')
                        ->required()
                        ->disk('local')
                        ->directory('media-uploads')
                        ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): ?string {
                            abort_if($this->submissionBatchId === null, 403);
                            // Validation has passed; expose recovery before staging I/O
                            // can consume the first temporary source.
                            $this->uploadBatchId = $this->submissionBatchId;

                            $uploader = UploadSiteMediaAction::make();
                            try {
                                return $uploader->storeTemporaryUpload($this->submissionBatchId, $file);
                            } catch (Throwable $throwable) {
                                $batch = $uploader->outcome($this->submissionBatchId);
                                throw_if($batch === null, $throwable);

                                report($throwable);
                                $this->uploadBatchId = $batch->id;
                                $this->notifyUploadBatch($batch);

                                throw new Halt($throwable->getMessage(), $throwable->getCode(), $throwable);
                            }
                        }),
                    Select::make('site_id')
                        ->label(__('capell-admin::media.upload_site'))
                        ->helperText(__('capell-admin::media.upload_site_helper'))
                        ->options(fn (): array => $this->siteOptions())
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data): null {
                    try {
                        return $this->uploadFiles($data);
                    } catch (ValidationException $validationException) {
                        throw $this->actionValidationException($validationException->errors());
                    }
                })
                ->visible(fn (): bool => SiteAccess::current()->query(Site::class)->exists()),
            Action::make('retry-upload-files')
                ->label(__('capell-admin::media.upload_retry'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->authorize(fn (): bool => static::getResource()::canCreate())
                ->visible(function (): bool {
                    $batch = $this->uploadBatchId === null ? null : UploadSiteMediaAction::make()->outcome($this->uploadBatchId);

                    return $batch !== null && $batch->hasRetryableFiles() && SiteAccess::current()->canSiteId($batch->siteId);
                })
                ->action(fn (): null => $this->retryUploadFiles()),
            Action::make('add-youtube-video')
                ->label(__('capell-admin::media.external_video_create'))
                ->icon('heroicon-o-play-circle')
                ->authorize(fn (): bool => static::getResource()::canCreate())
                ->schema([
                    TextInput::make('name')
                        ->label(__('capell-admin::media.external_video_name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('youtube_url')
                        ->label(__('capell-admin::media.external_video_url'))
                        ->helperText(__('capell-admin::media.external_video_url_helper'))
                        ->placeholder(__('capell-admin::media.external_video_url_placeholder'))
                        ->required()
                        ->url()
                        ->maxLength(2048),
                    Select::make('site_id')
                        ->label(__('capell-admin::media.external_video_site'))
                        ->options(fn (): array => $this->siteOptions())
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(fn (array $data): null => $this->createYouTubeVideoMedia($data))
                ->visible(fn (): bool => SiteAccess::current()->query(Site::class)->exists()),
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    private function siteOptions(): array
    {
        return SiteAccess::current()->query(Site::class)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createYouTubeVideoMedia(array $data): null
    {
        $video = YouTubeVideoUrl::parse((string) ($data['youtube_url'] ?? ''));

        if (! $video instanceof ExternalVideoData) {
            throw ValidationException::withMessages([
                'youtube_url' => __('capell-admin::media.external_video_invalid'),
            ]);
        }

        /** @var Site|null $site */
        $site = SiteAccess::current()->query(Site::class)
            ->whereKey((int) ($data['site_id'] ?? 0))
            ->first();

        if (! $site instanceof Site) {
            throw ValidationException::withMessages([
                'site_id' => __('validation.exists', ['attribute' => __('capell-admin::media.external_video_site')]),
            ]);
        }

        CreateExternalVideoMediaAction::run($site, (string) $data['name'], $video);

        Notification::make()
            ->title(__('capell-admin::media.external_video_created'))
            ->success()
            ->send();

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function uploadFiles(array $data): null
    {
        abort_unless(static::getResource()::canCreate(), 403);
        $site = $this->uploadSite($data);
        $paths = collect(is_array($data['files'] ?? null) ? $data['files'] : [])
            ->flatten()
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->unique()->sort()->values()->all();
        if ($paths === []) {
            throw ValidationException::withMessages(['files' => __('capell-admin::media.upload_files_required')]);
        }

        $uploader = UploadSiteMediaAction::make();
        $batch = $this->submissionBatchId === null ? null : $uploader->outcome($this->submissionBatchId);
        abort_unless($batch !== null && $batch->siteId === (int) $site->getKey(), 403);
        $reservedPaths = array_column($batch->files, 'path');
        sort($reservedPaths);
        abort_unless($paths === $reservedPaths, 403);
        $this->uploadBatchId = $batch->id;

        return $this->retryUploadFiles();
    }

    /** @param array<string, mixed> $data */
    private function uploadSite(array $data): Site
    {
        /** @var Site|null $site */
        $site = SiteAccess::current()->query(Site::class)
            ->whereKey((int) ($data['site_id'] ?? 0))
            ->first();

        if (! $site instanceof Site) {
            throw ValidationException::withMessages([
                'site_id' => __('validation.exists', ['attribute' => __('capell-admin::media.upload_site')]),
            ]);
        }

        return $site;
    }

    /** @param array<string, list<string>> $messages */
    private function actionValidationException(array $messages): ValidationException
    {
        $statePath = $this->getMountedActionSchema()?->getStatePath();
        $errors = [];
        foreach ($messages as $field => $fieldMessages) {
            $errors[filled($statePath) ? $statePath . '.' . $field : $field] = $fieldMessages;
        }

        return ValidationException::withMessages($errors);
    }

    private function retryUploadFiles(): null
    {
        abort_unless(static::getResource()::canCreate(), 403);
        abort_if($this->uploadBatchId === null, 403);
        $uploader = UploadSiteMediaAction::make();
        $batch = $uploader->outcome($this->uploadBatchId);
        abort_unless($batch !== null && SiteAccess::current()->canSiteId($batch->siteId), 403);

        try {
            $batch = $uploader->retry($batch->id);
        } catch (Throwable $throwable) {
            report($throwable);
            // Preserve the last per-file checkpoint even when a later storage,
            // authorisation or post-commit operation aborts this request.
            $batch = $uploader->outcome($batch->id) ?? $batch;
        }

        $this->notifyUploadBatch($batch);

        return null;
    }

    private function notifyUploadBatch(MediaUploadBatchData $batch): void
    {
        $notification = Notification::make()
            ->title(trans_choice('capell-admin::media.upload_files_success', $batch->successfulCount(), ['count' => $batch->successfulCount()]))
            ->body(implode(' ', array_map(fn (MediaUploadFileData $file): string => __('capell-admin::media.upload_file_outcome', [
                'name' => e($file->name),
                'reason' => $this->uploadFileReason($file),
            ]), $batch->files)));

        if ($batch->hasWarnings()) {
            $notification->warning()->persistent();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    private function uploadFileReason(MediaUploadFileData $file): string
    {
        if ($file->reason === 'capell-admin::media.upload_file_successful') {
            return __('capell-admin::media.upload_file_successful');
        }

        if ($file->reason === 'capell-admin::media.upload_source_cleanup_failed') {
            return __('capell-admin::media.upload_source_cleanup_failed');
        }

        if ($file->reason === 'capell-admin::media.upload_file_failed') {
            return __('capell-admin::media.upload_file_failed');
        }

        if ($file->reason === 'capell-admin::media.upload_reconciliation_required') {
            return __('capell-admin::media.upload_reconciliation_required');
        }

        return __('capell-admin::media.upload_not_attempted');
    }
}
