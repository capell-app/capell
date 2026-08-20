<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class RecordStateScreenshotFixture
{
    private const string DisabledLayoutKey = 'record-state-disabled-unused';

    private const string PageLayoutKey = 'record-state-page-layout';

    private const string PageName = 'Scheduled page without an active URL';

    private const string PageSlug = 'scheduled-no-active-url';

    private const string MediaUuid = 'c6de44d7-a8d4-4c5d-9e24-7c6492811d48';

    /**
     * Seeds deterministic local records before the runner starts concurrent
     * captures. The runner visits the normal authenticated resource routes.
     */
    public static function initialize(): void
    {
        DB::transaction(static function (): void {
            $site = Site::query()->first();
            $blueprint = Blueprint::query()->pageType()->first();

            throw_if(! ($site instanceof Site) || ! ($blueprint instanceof Blueprint), ModelNotFoundException::class, 'The screenshot app must be seeded before building the record-state fixture.');

            $pageLayout = self::pageLayout($site);
            self::disabledLayoutFor($site);

            $page = Page::query()->firstOrNew(['name' => self::PageName]);

            if (! $page->exists) {
                $page->uuid = Str::uuid()->toString();
            }

            $page->fill([
                'site_id' => $site->getKey(),
                'layout_id' => $pageLayout->getKey(),
                'blueprint_id' => $blueprint->getKey(),
                'visible_from' => now()->addWeek(),
                'visible_until' => null,
            ])->save();

            $page->translations()->updateOrCreate(
                ['language_id' => $site->language_id],
                [
                    'title' => self::PageName,
                    'content' => '<p>This scheduled page demonstrates a page with no active public URL.</p>',
                    'meta' => ['slug' => self::PageSlug],
                ],
            );

            PageUrl::query()->updateOrCreate(
                [
                    'pageable_type' => $page->getMorphClass(),
                    'pageable_id' => $page->getKey(),
                    'language_id' => $site->language_id,
                    'url' => '/' . self::PageSlug,
                ],
                [
                    'site_id' => $site->getKey(),
                    'status' => false,
                    'type' => null,
                ],
            );

            self::ensureUnusedMedia($page);
        });
    }

    public static function pagesUrl(): string
    {
        return PageResource::getUrl('index');
    }

    public static function layoutsUrl(): string
    {
        return LayoutResource::getUrl('index');
    }

    public static function pageEditUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => self::page()]);
    }

    public static function mediaListUrl(): string
    {
        return MediaResource::getUrl('index');
    }

    public static function mediaEditUrl(): string
    {
        $media = self::media();

        if (! $media instanceof Media) {
            throw new ModelNotFoundException('The record-state screenshot fixture has not been initialized.');
        }

        return MediaResource::getUrl('edit', ['record' => $media]);
    }

    public static function page(): Page
    {
        $page = Page::query()->where('name', self::PageName)->first();

        if (! $page instanceof Page) {
            throw new ModelNotFoundException('The record-state screenshot fixture has not been initialized.');
        }

        return $page;
    }

    public static function disabledLayout(): Layout
    {
        $layout = Layout::query()->where('key', self::DisabledLayoutKey)->first();

        if (! $layout instanceof Layout) {
            throw new ModelNotFoundException('The record-state screenshot fixture has not been initialized.');
        }

        return $layout;
    }

    public static function media(): ?Media
    {
        return Media::query()->where('uuid', self::MediaUuid)->first();
    }

    private static function pageLayout(Site $site): Layout
    {
        return Layout::query()->updateOrCreate(
            [
                'site_id' => $site->getKey(),
                'key' => self::PageLayoutKey,
            ],
            [
                'name' => 'Record state page layout',
                'containers' => [],
                'default' => false,
                'status' => true,
            ],
        );
    }

    private static function disabledLayoutFor(Site $site): Layout
    {
        return Layout::query()->updateOrCreate(
            [
                'site_id' => $site->getKey(),
                'key' => self::DisabledLayoutKey,
            ],
            [
                'name' => 'Disabled unused layout',
                'containers' => [],
                'default' => false,
                'status' => false,
            ],
        );
    }

    private static function ensureUnusedMedia(Page $page): void
    {
        $media = Media::query()->firstOrNew(['uuid' => self::MediaUuid]);

        $sourcePath = dirname(__DIR__, 3) . '/packages/core/resources/screenshot-fixtures/record-state-image.svg';
        throw_if(! is_file($sourcePath), ModelNotFoundException::class, 'The screenshot seed image is missing.');

        $contents = file_get_contents($sourcePath);
        throw_if($contents === false, ModelNotFoundException::class, 'The screenshot seed image could not be read.');

        $media->fill([
            'collection_name' => MediaCollectionEnum::Image->value,
            'name' => 'Unused editorial image',
            'file_name' => 'record-state-image.svg',
            'mime_type' => 'image/svg+xml',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => strlen($contents),
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 0,
            'model_type' => $page->getMorphClass(),
            'model_id' => $page->getKey(),
        ])->save();

        Storage::disk($media->disk)->put($media->getKey() . '/' . $media->file_name, $contents);

        AssetAttachment::query()
            ->where('asset_type', $media->getMorphClass())
            ->where('asset_id', (string) $media->getKey())
            ->delete();
    }
}
