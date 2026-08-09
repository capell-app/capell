<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
                'title' => 'Welcome to Capell',
                'content' => '<p>Build and publish a clear, durable site with Capell.</p><p>This is the ordinary published homepage rendered by the local application.</p>',
                'meta' => $translationMeta,
            ])->save();

            $themeMeta = is_array($theme->meta) ? $theme->meta : [];
            $themeMeta['assets'] = [self::Stylesheet];
            Arr::set($themeMeta, 'editor.assets.paths', [self::Stylesheet]);

            $theme->forceFill(['meta' => $themeMeta])->save();

            // The installed site keeps its real display domain. This additional
            // non-default domain lets the isolated HTTP workbench resolve the
            // same public page without redirecting the browser to a live host.
            // Site domains intentionally omit ports; screenshot-tools maps
            // portless local asset URLs back to the configured local server.
            $page->site->siteDomains()->updateOrCreate([
                'language_id' => $page->site->language_id,
                'domain' => $origin['host'],
                'scheme' => $origin['scheme'],
                'path' => null,
            ], [
                'default' => false,
                'status' => true,
            ]);

            resolve(CapellCacheManager::class)->flushCache();
        });
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
                && ($path === null || $path === '' || $path === '/')
                && ! isset($parts['query'])
                && ! isset($parts['fragment']),
            RuntimeException::class,
            'The frontend screenshot fixture requires a root local HTTP origin.',
        );

        return ['host' => $host, 'scheme' => $scheme];
    }
}
