<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\RegenerateSiteErrorPagesAction;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every file written by the static error page store, so a test can
 * assert that regeneration actually happened.
 */
class RecordingStaticErrorPageStore implements StaticErrorPageStore
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
}

function recordingStaticErrorPageStore(): RecordingStaticErrorPageStore
{
    $store = new RecordingStaticErrorPageStore;

    app()->instance(StaticErrorPageStore::class, $store);

    return $store;
}

function bindRecordingRenderer(): void
{
    $renderer = new class implements ThemePreviewRendererInterface
    {
        public function render(
            Theme $theme,
            Site $site,
            Page $page,
            ?Language $language = null,
            ?SiteDomain $siteDomain = null,
        ): Response {
            return new Response('<h1>' . ($page->translation?->title ?? '') . '</h1>');
        }
    };

    app()->instance(ThemePreviewRendererInterface::class, $renderer);
}

function bindThrowingRenderer(): void
{
    $renderer = new class implements ThemePreviewRendererInterface
    {
        public function render(
            Theme $theme,
            Site $site,
            Page $page,
            ?Language $language = null,
            ?SiteDomain $siteDomain = null,
        ): Response {
            throw new RuntimeException('boom');
        }
    };

    app()->instance(ThemePreviewRendererInterface::class, $renderer);
}

beforeEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

afterEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

it('regenerates error pages for an enabled site when the store is bound', function (): void {
    $store = recordingStaticErrorPageStore();
    bindRecordingRenderer();

    $language = Language::factory()->english()->create();
    $siteDomain = SiteDomain::factory()
        ->state(['language_id' => $language->id])
        ->create();

    RegenerateSiteErrorPagesAction::run($siteDomain->site_id);

    expect($store->files)->not->toBeEmpty();

    $manifest = resolve(ErrorPageManifestStore::class)->read();
    expect(data_get($manifest, 'sites.' . $siteDomain->site_id . '.entries.0'))->not->toBeNull();
});

it('is a no-op when the static error page store is not bound', function (): void {
    app()->forgetInstance(StaticErrorPageStore::class);

    expect(app()->bound(StaticErrorPageStore::class))->toBeFalse();

    $site = Site::factory()->create();

    RegenerateSiteErrorPagesAction::run($site->id);

    expect(File::exists(resolve(ErrorPageManifestStore::class)->path()))->toBeFalse();
});

it('is a no-op for an unknown site', function (): void {
    $store = recordingStaticErrorPageStore();
    bindRecordingRenderer();

    RegenerateSiteErrorPagesAction::run(999999);

    expect($store->files)->toBeEmpty();
});

it('is a no-op for a disabled site', function (): void {
    $store = recordingStaticErrorPageStore();
    bindRecordingRenderer();

    $site = Site::factory()->state(['status' => false])->create();

    RegenerateSiteErrorPagesAction::run($site->id);

    expect($store->files)->toBeEmpty();
});

it('swallows a renderer throwable and logs a warning once', function (): void {
    recordingStaticErrorPageStore();
    bindThrowingRenderer();

    $language = Language::factory()->english()->create();
    $siteDomain = SiteDomain::factory()
        ->state(['language_id' => $language->id])
        ->create();

    Log::shouldReceive('warning')->once();

    expect(fn () => RegenerateSiteErrorPagesAction::run($siteDomain->site_id))
        ->not->toThrow(Throwable::class);
});
