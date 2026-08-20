<?php

declare(strict_types=1);

namespace Capell\Core\Support\Screenshots;

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
use RuntimeException;

/**
 * Builds the deterministic record-state data used by local screenshot runs.
 *
 * This class deliberately has no dependency on Testbench or the Admin package:
 * combined screenshot queues boot the disposable consumer App, not the Core
 * workbench. The command that invokes it is guarded so this fixture cannot be
 * seeded by a normal production install or request.
 */
final class RecordStateScreenshotFixture
{
    private const string DisabledLayoutKey = 'record-state-disabled-unused';

    private const string PageLayoutKey = 'record-state-page-layout';

    private const string PageName = 'Scheduled page without an active URL';

    private const string PageSlug = 'scheduled-no-active-url';

    private const string PageUuid = '2f3f0f74-8e75-4ba2-9d5f-0d5a0ef8f1b8';

    private const string MediaUuid = 'c6de44d7-a8d4-4c5d-9e24-7c6492811d48';

    private const string FixtureMarkerKey = 'capell.screenshot_fixture';

    private const string FixtureMarkerValue = 'record-state';

    /**
     * Seed deterministic local records for the authenticated screenshot queue.
     *
     * @throws RuntimeException when called outside the explicit disposable
     *                          screenshot environment
     */
    public static function initialize(): void
    {
        self::assertDisposableScreenshotEnvironment();

        DB::transaction(static function (): void {
            $site = Site::query()->first();
            $blueprint = Blueprint::query()->pageType()->first();

            throw_if(
                ! ($site instanceof Site) || ! ($blueprint instanceof Blueprint),
                ModelNotFoundException::class,
                'The screenshot app must be seeded before building the record-state fixture.',
            );

            $page = self::fixturePage($site);
            $pageLayout = self::pageLayout($site);
            self::disabledLayoutFor($site);

            $page->fill([
                'site_id' => $site->getKey(),
                'name' => self::PageName,
                'layout_id' => $pageLayout->getKey(),
                'blueprint_id' => $blueprint->getKey(),
                'meta' => self::fixtureMetadata($page->meta),
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

    private static function assertDisposableScreenshotEnvironment(): void
    {
        $marker = getenv('CAPELL_SCREENSHOT_FIXTURE');
        $configuredAppPath = getenv('CAPELL_SCREENSHOT_APP_PATH');
        $basePath = realpath(base_path());
        $appPath = is_string($configuredAppPath) ? realpath($configuredAppPath) : false;

        throw_unless(
            app()->environment(['local', 'testing'])
                && in_array($marker, ['1', 'true', 'record-state'], true)
                && is_string($basePath)
                && is_string($appPath)
                && $basePath === $appPath,
            RuntimeException::class,
            'Record-state screenshot fixtures require the explicit disposable local screenshot environment.',
        );
    }

    private static function fixturePage(Site $site): Page
    {
        $pages = Page::withTrashed()->where('uuid', self::PageUuid)->get();

        throw_if(
            $pages->count() > 1,
            RuntimeException::class,
            'The record-state screenshot page identity is duplicated across records.',
        );

        $page = $pages->first();

        if ($page instanceof Page) {
            throw_if(
                $page->trashed()
                    || $page->site_id !== $site->getKey()
                    || $page->name !== self::PageName
                    || data_get($page->meta, self::FixtureMarkerKey) !== self::FixtureMarkerValue,
                RuntimeException::class,
                'The record-state screenshot page identity is already owned by another record.',
            );

            return $page;
        }

        throw_if(
            Page::withTrashed()
                ->where('site_id', $site->getKey())
                ->where('name', self::PageName)
                ->exists(),
            RuntimeException::class,
            'A same-site page already uses the record-state screenshot display name.',
        );

        $page = new Page;
        $page->uuid = self::PageUuid;

        return $page;
    }

    /**
     * @param  array<array-key, mixed>|null  $existing
     * @return array<array-key, mixed>
     */
    private static function fixtureMetadata(?array $existing): array
    {
        $metadata = $existing ?? [];
        data_set($metadata, self::FixtureMarkerKey, self::FixtureMarkerValue);

        return $metadata;
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
        $media = Media::withTrashed()->where('uuid', self::MediaUuid)->first();

        if ($media instanceof Media) {
            throw_if(
                $media->trashed()
                    || $media->model_type !== $page->getMorphClass()
                    || (string) $media->model_id !== (string) $page->getKey()
                    || data_get($media->custom_properties, self::FixtureMarkerKey) !== self::FixtureMarkerValue,
                RuntimeException::class,
                'The record-state screenshot media identity is already owned by another record.',
            );

            throw_if(
                AssetAttachment::query()
                    ->where('asset_type', $media->getMorphClass())
                    ->where('asset_id', (string) $media->getKey())
                    ->exists(),
                RuntimeException::class,
                'The record-state screenshot media is attached and cannot be reused.',
            );
        } else {
            $media = new Media;
            $media->uuid = self::MediaUuid;
        }

        $sourcePath = dirname(__DIR__, 5) . '/artwork/foundation-series/references/capell-logo-reference.png';

        throw_if(! is_file($sourcePath), ModelNotFoundException::class, 'The screenshot seed image is missing.');

        $contents = file_get_contents($sourcePath);

        throw_if($contents === false, ModelNotFoundException::class, 'The screenshot seed image could not be read.');

        $media->fill([
            'collection_name' => MediaCollectionEnum::Image->value,
            'name' => 'Unused editorial image',
            'file_name' => 'capell-logo-reference.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => strlen($contents),
            'manipulations' => [],
            'custom_properties' => self::fixtureMetadata($media->custom_properties),
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 0,
            'model_type' => $page->getMorphClass(),
            'model_id' => $page->getKey(),
        ])->save();

        Storage::disk($media->disk)->put($media->getKey() . '/' . $media->file_name, $contents);
    }
}
