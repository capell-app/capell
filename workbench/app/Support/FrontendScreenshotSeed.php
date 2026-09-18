<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Prepares deterministic generated-fixture state for screenshot capture.
 * Installed-route and browser evidence must still be captured separately.
 */
final class FrontendScreenshotSeed
{
    private const string Stylesheet = 'build/screenshots/default-theme.css';

    public static function initialize(string $frontendOrigin): void
    {
        $origin = self::localOrigin($frontendOrigin);

        DB::transaction(static function () use ($origin): void {
            $page = Page::query()
                ->homePage()
                ->with(['layout.theme', 'site.theme'])
                ->first();

            throw_if(! $page instanceof Page, ModelNotFoundException::class, 'The screenshot app must be seeded before building the generated frontend screenshot fixture.');

            $layout = $page->layout;
            throw_if(! $layout instanceof Layout, ModelNotFoundException::class, 'The screenshot homepage has no layout.');

            $theme = $layout->theme ?? $page->site->theme;
            throw_if(! $theme instanceof Theme, ModelNotFoundException::class, 'The screenshot homepage resolves no theme.');

            throw_unless(
                is_file(public_path(self::Stylesheet)),
                RuntimeException::class,
                'The generated frontend screenshot stylesheet is missing. Run the screenshot workbench preparation before seeding the fixture.',
            );
            $stylesheet = (string) file_get_contents(public_path(self::Stylesheet));
            throw_unless(
                preg_match('/\.capell-default-theme\s*\{/', $stylesheet) === 1,
                RuntimeException::class,
                'The frontend screenshot requires compiled default-theme CSS, not a placeholder stylesheet.',
            );

            $layout->forceFill([
                'containers' => [
                    'main' => [
                        'elements' => [
                            ['element_key' => 'page-content', 'occurrence' => 1],
                        ],
                    ],
                ],
            ])->save();

            $translation = $page->translations()->firstOrNew([
                'language_id' => $page->site->language_id,
            ]);
            $translationMeta = is_array($translation->meta) ? $translation->meta : [];
            $translationMeta['slug'] = '/';

            $translation->fill([
                'title' => 'A slower weekend outdoors',
                'content' => self::content($page),
                'meta' => $translationMeta,
            ])->save();

            $themeMeta = is_array($theme->meta) ? $theme->meta : [];
            $themeMeta['assets'] = [self::Stylesheet];
            Arr::set($themeMeta, 'editor.assets.paths', [self::Stylesheet]);

            $theme->forceFill(['meta' => $themeMeta])->save();

            // The installed site keeps its real display domain. This additional
            // non-default domain lets the isolated HTTP workbench resolve the
            // same public page without redirecting the browser to a live host.
            // Fresh fixture databases can have no default domain at all; in
            // that case the local domain must become the default so preview
            // renderers can resolve the site's canonical origin.
            // Site domains intentionally omit ports; screenshot-tools maps
            // portless local asset URLs back to the configured local server.
            $site = $page->site;
            $hasDefaultDomain = $site->siteDomain()->exists();

            $siteDomain = $site->siteDomains()->updateOrCreate([
                'language_id' => $page->site->language_id,
                'domain' => $origin['host'],
                'scheme' => $origin['scheme'],
                'path' => null,
            ], [
                'status' => true,
            ]);

            if (! $hasDefaultDomain) {
                $siteDomain->forceFill(['default' => true])->save();
            }

            resolve(CapellCacheManager::class)->flushCache();
        });
    }

    private static function content(Page $page): string
    {
        $source = dirname(__DIR__, 2) . '/resources/images/coastal-walk.svg';
        $contents = file_get_contents($source);
        throw_unless(is_string($contents), RuntimeException::class, 'The coastal walk editorial image is missing.');

        $media = Media::query()->firstOrNew(['uuid' => '6b6f1639-95be-4cc3-a5a5-f19a0ef825dc']);
        $media->fill([
            'collection_name' => 'image',
            'name' => 'Coastal walk at morning light',
            'file_name' => 'coastal-walk.svg',
            'mime_type' => 'image/svg+xml',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => strlen($contents),
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'model_type' => $page->getMorphClass(),
            'model_id' => $page->getKey(),
        ])->save();
        Storage::disk('public')->put($media->getKey() . '/' . $media->file_name, $contents);

        return view()->file(dirname(__DIR__, 2) . '/resources/views/screenshot-fixtures/frontend-content.blade.php', ['imageUrl' => $media->getUrl()])->render();
    }

    /** @return array{host: string, scheme: string} */
    private static function localOrigin(string $frontendOrigin): array
    {
        $parts = parse_url($frontendOrigin);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;

        throw_unless(
            is_string($host)
                && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
                && is_string($scheme)
                && in_array($scheme, ['http', 'https'], true)
                && in_array($path, [null, '', '/'], true)
                && ! isset($parts['query'])
                && ! isset($parts['fragment']),
            RuntimeException::class,
            'The frontend screenshot fixture requires a root local HTTP origin.',
        );

        return ['host' => $host, 'scheme' => $scheme];
    }
}
