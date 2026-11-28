<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Frontend\Actions\GenerateStaticPageArtifactsAction;
use Capell\Frontend\Data\ApplicationAreaData;
use Capell\Frontend\Support\Application\ApplicationAreaRegistry;
use Capell\Frontend\Support\Cache\PrivateFrontendResponse;
use Capell\Frontend\Support\Static\StaticPageArtifactPathResolver;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false, 'cache.default' => 'array']);
    Cache::flush();

    $this->site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost', 'scheme' => 'http', 'path' => null, 'default' => true,
    ])->create();
    Page::factory()->site($this->site)->home()->withTranslations(slug: '/')->create(['meta' => null]);

    resolve(ApplicationAreaRegistry::class)->register(new ApplicationAreaData(key: 'account', pageType: 'account'));
    Page::factory()->site($this->site)
        ->type(resolve(BlueprintCreator::class)->applicationPageType('account', 'Account'))
        ->withTranslations(slug: 'my-account')
        ->create(['meta' => null]);

    Route::middleware(['web', 'frontend.application:account'])->get('/my-account', fn (Request $request): string => 'Signed in as ' . ($request->user()?->email ?? 'a visitor'));
});

afterEach(function (): void {
    File::deleteDirectory(resolve(StaticPageArtifactStore::class)->root());
});

function exportedFileFor(Site $site, string $url): string
{
    $pageUrl = PageUrl::query()->where('site_id', $site->id)->where('url', $url)->firstOrFail();
    $domain = SiteDomain::query()->where('site_id', $site->id)->firstOrFail();

    return resolve(StaticPageArtifactStore::class)->root() . '/' . resolve(StaticPageArtifactPathResolver::class)->pathForPageUrl($pageUrl, $domain);
}

it('writes no static copy of a private application page and lists it as served only by Laravel', function (): void {
    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $this->site->id);

    expect($manifest['withheld'])->toContain('/my-account')
        ->and(array_column($manifest['artifacts'], 'url'))->not->toContain('/my-account')
        ->and(File::exists(exportedFileFor($this->site, '/my-account')))->toBeFalse()
        // The public home page in the same export is still written.
        ->and(File::exists(exportedFileFor($this->site, '/')))->toBeTrue();
});

it('withdraws a copy exported before the page became private', function (): void {
    $stale = exportedFileFor($this->site, '/my-account');
    File::ensureDirectoryExists(dirname($stale));
    File::put($stale, '<html>Signed in as someone else</html>');

    GenerateStaticPageArtifactsAction::run(siteId: $this->site->id);

    expect(File::exists($stale))->toBeFalse();
});

it('withholds a page its route marks private, even when it redirects, instead of failing the export', function (): void {
    Page::factory()->site($this->site)->withTranslations(slug: 'orders')->create(['meta' => null]);
    Route::middleware('web')->get('/orders', function (Request $request): RedirectResponse {
        PrivateFrontendResponse::mark($request);

        return redirect('/login');
    });
    $stale = exportedFileFor($this->site, '/orders');
    File::ensureDirectoryExists(dirname($stale));
    File::put($stale, '<html>Orders for someone else</html>');

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $this->site->id);

    expect($manifest['withheld'])->toContain('/orders')
        ->and(File::exists($stale))->toBeFalse();
});

it('withholds an application page whose sign-in middleware answers before the page is resolved', function (): void {
    // The documented route order puts auth first, so a signed-out export request is
    // redirected before the application middleware can mark it private.
    Route::get('/login', fn (): string => 'Sign in')->name('login');
    Page::factory()->site($this->site)
        ->type(resolve(BlueprintCreator::class)->applicationPageType('account', 'Account'))
        ->withTranslations(slug: 'account-settings')
        ->create(['meta' => null]);
    Route::middleware(['web', 'auth', 'frontend.application:account'])->get('/account-settings', fn (): string => 'Settings');
    $stale = exportedFileFor($this->site, '/account-settings');
    File::ensureDirectoryExists(dirname($stale));
    File::put($stale, '<html>Settings for someone else</html>');

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $this->site->id);

    expect($manifest['withheld'])->toContain('/account-settings')
        ->and(File::exists($stale))->toBeFalse();
});

it('keeps a public page that answers with a no-store redirect as a failure rather than withdrawing it', function (): void {
    // Visitor language detection redirects with no-store; that says nothing about the page.
    Page::factory()->site($this->site)->withTranslations(slug: 'about')->create(['meta' => null]);
    Route::middleware('web')->get('/about', fn (): RedirectResponse => redirect('/de/about')->withHeaders(['Cache-Control' => 'no-store, private']));
    $exported = exportedFileFor($this->site, '/about');
    File::ensureDirectoryExists(dirname($exported));
    File::put($exported, '<html>About us</html>');

    expect(fn (): mixed => GenerateStaticPageArtifactsAction::run(siteId: $this->site->id))
        ->toThrow(RuntimeException::class, '/about: HTTP 302')
        ->and(File::exists($exported))->toBeTrue();
});

it('withdraws an earlier copy of a page whose output is no longer safe to publish', function (): void {
    Page::factory()->site($this->site)->withTranslations(slug: 'contact')->create(['meta' => null]);
    Route::middleware('web')->get('/contact', fn (): string => '<html><head><meta name="csrf-token" content="' . csrf_token() . '"></head><body>Contact</body></html>');
    $stale = exportedFileFor($this->site, '/contact');
    File::ensureDirectoryExists(dirname($stale));
    File::put($stale, '<html>Contact</html>');

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $this->site->id);

    expect($manifest['withheld'])->toContain('/contact')
        ->and(File::exists($stale))->toBeFalse();
});

it('leaves no empty directory for a private page that was never exported', function (): void {
    GenerateStaticPageArtifactsAction::run(siteId: $this->site->id);

    expect(File::isDirectory(dirname(exportedFileFor($this->site, '/my-account'))))->toBeFalse();
});
