<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\GenerateMaintenancePageCacheAction;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    File::delete(resolve(MaintenanceManifestStore::class)->path());
});

afterEach(function (): void {
    File::delete(resolve(MaintenanceManifestStore::class)->path());
});

it('generates static maintenance html and updates the manifest for each site domain', function (): void {
    $store = new class implements StaticMaintenancePageStore
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
                'site_domain_path' => $site->siteDomain?->path,
            ];

            return new Response('<h1>Maintenance</h1>');
        }
    };

    app()->instance(StaticMaintenancePageStore::class, $store);
    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    $language = Language::factory()->english()->create();
    $siteDomain = SiteDomain::factory()
        ->state([
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => '/docs',
            'language_id' => $language->id,
        ])
        ->create();

    GenerateMaintenancePageCacheAction::run($siteDomain->site);

    expect($store->files)->toHaveKey('maintenance/https.example.test/docs/index.html')
        ->and($store->files['maintenance/https.example.test/docs/index.html'])->toBe('<h1>Maintenance</h1>')
        ->and(data_get(resolve(MaintenanceManifestStore::class)->read(), 'sites.' . $siteDomain->site_id . '.domains.0.file'))
        ->toBe('maintenance/https.example.test/docs/index.html')
        ->and($renderer->calls[0]['language_id'])->toBe($language->id)
        ->and($renderer->calls[0]['site_domain_id'])->toBe($siteDomain->id)
        ->and($renderer->calls[0]['site_domain_path'])->toBe('/docs');
});
