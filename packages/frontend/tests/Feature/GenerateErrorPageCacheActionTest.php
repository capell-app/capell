<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Actions\GenerateErrorPageCacheAction;
use Capell\Frontend\Actions\RegenerateSiteErrorPagesAction;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Enums\ErrorPageStatusEnum;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

afterEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

it('generates static error html per status and updates the manifests', function (): void {
    $store = new class implements StaticErrorPageStore
    {
        /** @var array<string, string> */
        public array $files = [];

        public function exists(string $file): bool
        {
            return array_key_exists($file, $this->files);
        }

        public function path(string $file): ?string
        {
            return $this->exists($file) ? storage_path('framework/testing/' . str_replace('/', '-', $file)) : null;
        }

        public function put(string $file, string $contents): void
        {
            $this->files[$file] = $contents;
        }
    };

    $renderer = new class implements ThemePreviewRendererInterface
    {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function render(
            Theme $theme,
            Site $site,
            Page $page,
            ?Language $language = null,
            ?SiteDomain $siteDomain = null,
        ): Response {
            $this->calls[] = [
                'language_id' => $language?->id,
                'site_domain_id' => $siteDomain?->id,
                'title' => $page->translation?->title,
                'content' => $page->translation?->content,
            ];

            return new Response('<h1>' . ($page->translation?->title ?? '') . '</h1>');
        }
    };

    app()->instance(StaticErrorPageStore::class, $store);
    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    // Isolate this test from the error-page invalidation observer, which would
    // otherwise regenerate the cache on every model save and double the calls.
    RegenerateSiteErrorPagesAction::allowToRun();

    $language = Language::factory()->english()->create();
    $siteDomain = SiteDomain::factory()
        ->state([
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => '/docs',
            'language_id' => $language->id,
        ])
        ->create();

    $entries = GenerateErrorPageCacheAction::run($siteDomain->site);

    $statuses = ErrorPageStatusEnum::statusValues();

    // A file per status, with the /{status}/index.html shape.
    foreach ($statuses as $status) {
        expect($store->files)->toHaveKey('error/https.example.test/docs/' . $status . '/index.html');
    }

    // One manifest entry per (domain, locale, status).
    expect($entries)->toHaveCount(count($statuses));

    // Renderer invoked once per (domain, locale, status).
    expect($renderer->calls)->toHaveCount(count($statuses));

    // Status-specific copy fed into the renderer (500 headline).
    $page = resolve(PageCreator::class)
        ->createErrorPage($siteDomain->site, $siteDomain->site->getAllLanguages());
    $errorTranslation = $page->translations()->where('language_id', $language->id)->first();
    throw_unless($errorTranslation instanceof Translation, RuntimeException::class, 'Expected error page translation.');
    $errorMeta = $errorTranslation->meta;
    throw_unless(is_array($errorMeta) && isset($errorMeta['error_status_copy']), RuntimeException::class, 'Expected error_status_copy meta.');
    $statusCopy = $errorMeta['error_status_copy'];
    $expected500Title = $statusCopy[500]['headline'];

    $call500 = collect($renderer->calls)->firstWhere('title', $expected500Title);
    throw_unless(is_array($call500), RuntimeException::class, 'Expected renderer call for status 500.');
    expect($call500['content'])->toBe('<p>' . $statusCopy[500]['description'] . '</p>');

    // Site manifest entry recorded with status + file.
    $manifest = resolve(ErrorPageManifestStore::class)->read();
    $firstEntry = data_get($manifest, 'sites.' . $siteDomain->site_id . '.entries.0');
    expect($firstEntry['file'])->toBe('error/https.example.test/docs/' . $statuses[0] . '/index.html')
        ->and($firstEntry['status'])->toBe($statuses[0]);

    // Fallback manifest written with host -> logo + copy, plus default.
    $fallback = resolve(ErrorPageFallbackManifestStore::class)->read();
    expect($fallback['hosts'])->toHaveKey('example.test')
        ->and($fallback['hosts']['example.test']['logo_url'])->toBeString()
        ->and($fallback['hosts']['example.test']['copy'])->toHaveKey('500')
        ->and($fallback['default']['copy'])->toHaveKey('500');
});
