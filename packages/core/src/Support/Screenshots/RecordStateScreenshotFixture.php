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
use Illuminate\Support\Str;
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

    private const string MediaUuid = 'c6de44d7-a8d4-4c5d-9e24-7c6492811d48';

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
