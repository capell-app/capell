<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Media\Pages;

use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Actions\Media\UploadSiteMediaAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Override;

class ListMedia extends ListRecords
{
    use HasImportExportHeaderActions;

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
                ->schema([
                    FileUpload::make('files')
                        ->label(__('capell-admin::media.upload_files'))
                        ->multiple()
                        ->required()
                        ->disk('local')
                        ->directory('media-uploads'),
                    Select::make('site_id')
                        ->label(__('capell-admin::media.upload_site'))
                        ->helperText(__('capell-admin::media.upload_site_helper'))
                        ->options(fn (): array => $this->siteOptions())
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(fn (array $data): null => $this->uploadFiles($data))
                ->visible(fn (): bool => SiteScope::applyForCurrentActor(Site::query(), 'id')->exists()),
            Action::make('add-youtube-video')
                ->label(__('capell-admin::media.external_video_create'))
                ->icon('heroicon-o-play-circle')
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
                ->visible(fn (): bool => SiteScope::applyForCurrentActor(Site::query(), 'id')->exists()),
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    private function siteOptions(): array
    {
        return SiteScope::applyForCurrentActor(Site::query(), 'id')
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
        $site = SiteScope::applyForCurrentActor(Site::query(), 'id')
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
        /** @var Site|null $site */
        $site = SiteScope::applyForCurrentActor(Site::query(), 'id')
            ->whereKey((int) ($data['site_id'] ?? 0))
            ->first();

        if (! $site instanceof Site) {
            throw ValidationException::withMessages([
                'site_id' => __('validation.exists', ['attribute' => __('capell-admin::media.upload_site')]),
            ]);
        }

        $uploadedCount = UploadSiteMediaAction::run($site, $data['files'] ?? []);

        Notification::make()
            ->title(trans_choice('capell-admin::media.upload_files_success', $uploadedCount, ['count' => $uploadedCount]))
            ->success()
            ->send();

        return null;
    }
}
