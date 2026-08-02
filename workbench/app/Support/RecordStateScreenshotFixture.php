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
use Illuminate\Support\Str;

final class RecordStateScreenshotFixture
{
    private const string DisabledLayoutKey = 'record-state-disabled-unused';

    private const string PageLayoutKey = 'record-state-page-layout';

    private const string PageName = 'Scheduled page without an active URL';

    private const string PageSlug = 'scheduled-no-active-url';

    private const string MediaUuid = 'c6de44d7-a8d4-4c5d-9e24-7c6492811d48';

    public static function pagesUrl(): string
    {
        self::rebuild();

        return PageResource::getUrl('index');
    }

    public static function layoutsUrl(): string
    {
        self::rebuild();

        return LayoutResource::getUrl('index');
    }

    public static function pageEditUrl(): string
    {
        $page = self::rebuild();

        return PageResource::getUrl('edit', ['record' => $page]);
    }

    public static function mediaListUrl(): string
    {
        self::rebuild();

        return MediaResource::getUrl('index');
    }

    public static function mediaEditUrl(): string
    {
        self::rebuild();

        return MediaResource::getUrl('edit', ['record' => self::media()]);
    }

    public static function page(): Page
    {
        self::rebuild();

        return Page::query()->where('name', self::PageName)->sole();
    }

    public static function disabledLayout(): Layout
    {
        self::rebuild();

        return Layout::query()->where('key', self::DisabledLayoutKey)->sole();
    }

    public static function media(): Media
    {
        return Media::query()->where('uuid', self::MediaUuid)->sole();
    }

    private static function rebuild(): Page
    {
        return DB::transaction(function (): Page {
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
                    'content' => '<p>This scheduled fixture demonstrates a page with no active public URL.</p>',
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

            return $page->refresh();
        });
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

        $media->fill([
            'collection_name' => MediaCollectionEnum::Image->value,
            'name' => 'Unused screenshot fixture image',
            'file_name' => 'unused-screenshot-fixture.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 0,
            'model_type' => $page->getMorphClass(),
            'model_id' => $page->getKey(),
        ])->save();

        AssetAttachment::query()
            ->where('asset_type', $media->getMorphClass())
            ->where('asset_id', (string) $media->getKey())
            ->delete();
    }
}
