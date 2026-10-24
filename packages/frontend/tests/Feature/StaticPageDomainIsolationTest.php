<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Actions\BuildErrorPageCopyDiagnosticsAction;
use Capell\Frontend\Actions\BuildStaticErrorPageDiagnosticsAction;
use Capell\Frontend\Actions\GenerateErrorPageCacheAction;
use Capell\Frontend\Actions\GenerateMaintenancePageCacheAction;
use Capell\Frontend\Actions\RegenerateSiteErrorPagesAction;
use Capell\Frontend\Actions\ResolveStaticErrorPageAction;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Http\Middleware\ServeStaticMaintenancePage;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifest;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    foreach ([ErrorPageManifestStore::class, ErrorPageFallbackManifestStore::class, MaintenanceManifestStore::class] as $store) {
        File::delete(resolve($store)->path());
    }

    RegenerateSiteErrorPagesAction::allowToRun();
    $store = new class implements StaticErrorPageStore, StaticMaintenancePageStore
    {
        /** @var array<string, string> */
        public array $files = [];

        #[Override]
        public function exists(string $file): bool
        {
            return isset($this->files[$file]);
        }

        #[Override]
        public function put(string $file, string $contents): void
        {
            $this->files[$file] = $contents;
        }

        #[Override]
        public function path(string $file): ?string
        {
            if (! $this->exists($file)) {
                return null;
            }

            $path = storage_path('framework/testing/domain-isolation/' . hash('sha256', $file) . '.html');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $this->files[$file]);

            return $path;
        }
    };
    app()->instance(StaticErrorPageStore::class, $store);
    app()->instance(StaticMaintenancePageStore::class, $store);
    app()->instance(ThemePreviewRendererInterface::class, new class implements ThemePreviewRendererInterface
    {
        #[Override]
        public function render(Theme $theme, Site $site, Page $page, ?Language $language = null, ?SiteDomain $siteDomain = null): Response
        {
            return new Response('<h1>Site ' . $site->id . '</h1>');
        }
    });
});

afterEach(function (): void {
    foreach ([ErrorPageManifestStore::class, ErrorPageFallbackManifestStore::class, MaintenanceManifestStore::class] as $store) {
        File::delete(resolve($store)->path());
    }

    File::deleteDirectory(storage_path('framework/testing/domain-isolation'));
});

it('keeps generated error and maintenance pages bound to their routing domain', function (?string $scheme, ?int $port, string $path, bool $otherFirst): void {
    config()->set('capell-frontend.default_scheme', 'https');
    $language = Language::factory()->english()->create();
    $victim = SiteDomain::factory()->create([
        'scheme' => 'https', 'domain' => 'shared.test', 'port' => null, 'path' => '/', 'language_id' => $language->id,
    ]);
    $other = SiteDomain::factory()->create([
        'scheme' => $scheme, 'domain' => 'shared.test', 'port' => $port, 'path' => $path, 'language_id' => $language->id,
    ]);
    $domains = $otherFirst ? [$other, $victim] : [$victim, $other];
    $errors = [];
    $maintenance = [];
    foreach ($domains as $domain) {
        $page = resolve(PageCreator::class)->createErrorPage($domain->site, $domain->site->getAllLanguages());
        $translation = $page->translations()->where('language_id', $language->id)->firstOrFail();
        $translation->update(['meta' => ['error_status_copy' => [404 => [
            'headline' => 'Site ' . $domain->site_id, 'description' => 'Private site branding',
        ]]]]);
        $errors[$domain->id] = GenerateErrorPageCacheAction::run($domain->site);
        $maintenance[$domain->id] = GenerateMaintenancePageCacheAction::run($domain->site);
        resolve(MaintenanceManifestStore::class)->setSiteActive($domain->site_id, true);
    }

    $victimError = collect($errors[$victim->id])->firstWhere('status', '404');
    $otherError = collect($errors[$other->id])->firstWhere('status', '404');
    expect($victimError['file'] ?? null)->toBeString()->not->toBe($otherError['file'] ?? null)
        ->and($maintenance[$victim->id][0]['file'])->not->toBe($maintenance[$other->id][0]['file']);

    foreach ([$victim, $other] as $domain) {
        $requestScheme = $domain === $other && $scheme === null ? 'http' : $domain->scheme;
        $requestPort = $domain->port ?? ($requestScheme === 'https' ? 443 : 80);
        $requestPath = rtrim((string) ($domain->path ?? '/'), '/') . '/missing';
        $html = '<h1>Site ' . $domain->site_id . '</h1>';
        expect(ResolveStaticErrorPageAction::run($requestScheme, 'shared.test', $requestPath, '404', $requestPort))->toBe($html);
        $diagnostics = BuildStaticErrorPageDiagnosticsAction::run($requestScheme, 'shared.test', $requestPath, '404', $requestPort);
        expect($diagnostics->resolved)->toBeTrue()
            ->and(file_get_contents($diagnostics->resolvedFilePath))->toBe($html)
            ->and(ErrorPageFallbackManifest::forHost('shared.test', '404', $requestScheme, $requestPort, $requestPath)['copy']['headline'] ?? null)
            ->toBe('Site ' . $domain->site_id);
        $request = Request::create($requestScheme . '://shared.test:' . $requestPort . $requestPath);
        $originalRequest = resolve('request');
        app()->instance('request', $request);
        try {
            expect(ResolveStaticErrorPageAction::run($requestScheme, 'shared.test', $requestPath, '404'))->toBe($html);
        } finally {
            app()->instance('request', $originalRequest);
        }

        expect(BuildErrorPageCopyDiagnosticsAction::run('shared.test', '404', $requestScheme, $requestPort, $requestPath)->winners['headline'])
            ->toBe('Site ' . $domain->site_id);
        $response = resolve(ServeStaticMaintenancePage::class)->handle(
            $request,
            fn (): Response => new Response('fresh'),
        );
        expect($response->getStatusCode())->toBe(503)->and($response->getContent())->toBe($html);
    }

    expect(ErrorPageFallbackManifest::forHost('unrelated.test', '404')['copy'])->toBeNull();
})->with([
    'different port' => ['https', 8443, '/', false],
    'different port reversed' => ['https', 8443, '/', true],
    'wildcard scheme' => [null, null, '/', false],
    'wildcard scheme reversed' => [null, null, '/', true],
    'mounted domain' => ['https', null, '/docs', false],
    'mounted domain reversed' => ['https', null, '/docs', true],
]);

it('converts legacy maintenance routes before matching a non-default port', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'shared.test', 'port' => 8443, 'path' => '/']);
    $store = resolve(MaintenanceManifestStore::class);
    $store->write(['sites' => [$domain->site_id => ['active' => true, 'domains' => [[
        'scheme' => 'https', 'domain' => 'shared.test', 'path' => '/',
        'site_domain_id' => $domain->id, 'file' => 'legacy.html',
    ]]]]]);

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://shared.test:8443/missing'),
        fn (): Response => new Response('application'),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('<h1>Site ' . $domain->site_id . '</h1>')
        ->and($store->read()['version'] ?? null)->toBe(2)
        ->and($store->read()['sites'][$domain->site_id]['domains'][0]['port'])->toBe(8443);
});

it('denies legacy maintenance generically on conversion failure and detects old worker rewrites', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'shared.test', 'port' => 8443, 'path' => '/']);
    $store = resolve(MaintenanceManifestStore::class);
    $legacy = ['sites' => [$domain->site_id => ['active' => true, 'domains' => [[
        'scheme' => 'https', 'domain' => 'shared.test', 'path' => '/', 'file' => 'legacy.html',
    ]]]]];
    // A deployment may already have converted the file before an old worker replaces it.
    GenerateMaintenancePageCacheAction::run($domain->site);
    resolve(StaticMaintenancePageStore::class)->put('legacy.html', 'WRONG OWNER');
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->twice()->andThrow(new RuntimeException('Offline'));
    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    foreach ([null, 2] as $version) {
        // Missing routing fields remain untrusted even if a stale writer retained the version.
        $store->write(array_merge($legacy, $version === null ? [] : ['version' => $version]));
        $called = false;
        $response = resolve(ServeStaticMaintenancePage::class)->handle(
            Request::create('https://shared.test:8443/missing'),
            function () use (&$called): Response {
                $called = true;

                return new Response('application');
            },
        );
        expect($response->getStatusCode())->toBe(503)
            ->and($response->getContent())->not->toContain('WRONG OWNER', 'application')
            ->and($called)->toBeFalse();
    }
});

it('denies requests during partial legacy manifest rewrites', function (string $contents): void {
    $path = resolve(MaintenanceManifestStore::class)->path();
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://shared.test:8443/missing'),
        fn (): Response => new Response('application'),
    );
    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->not->toContain('application');
})->with(['', '{', 'null', '{"sites":"invalid"}']);
