<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheTime;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Contracts\ApplicationNavigationResolver;
use Capell\Frontend\Data\ApplicationAreaData;
use Capell\Frontend\Data\ApplicationNavigationItemData;
use Capell\Frontend\Data\ApplicationPageData;
use Capell\Frontend\Http\Middleware\ResolveApplicationPageContext;
use Capell\Frontend\Support\Application\ApplicationAreaRegistry;
use Capell\Frontend\Support\Cache\PrivateFrontendResponse;
use Capell\Frontend\Support\Kernel\Steps\ApplicationPageResolveStep;
use Capell\Frontend\Support\Kernel\Steps\ApplyLocaleStep;
use Capell\Frontend\Support\Kernel\Steps\LayoutResolverStep;
use Capell\Frontend\Support\Kernel\Steps\PageResolveStep;
use Capell\Frontend\Support\Kernel\Steps\SiteResolveStep;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

beforeEach(function (): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false]);
    Cache::flush();

    $this->site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost', 'scheme' => 'http', 'path' => null, 'default' => true,
    ])->create();
    Page::factory()->site($this->site)->home()->withTranslations(slug: '/')->create(['meta' => null]);

    resolve(ApplicationAreaRegistry::class)->register(new ApplicationAreaData(key: 'account', pageType: 'account'));

    Route::middleware(['web', 'frontend.application:account'])->get('/test-account', function (Request $request): mixed {
        $applicationPage = $request->attributes->get(ApplicationPageData::ATTRIBUTE);
        $navigation = $applicationPage instanceof ApplicationPageData
            ? implode(',', array_map(fn (ApplicationNavigationItemData $item): string => $item->label, $applicationPage->navigation))
            : '';

        return view('capell::application', [
            'slot' => new HtmlString(sprintf(
                '<main id="account-content">Signed in as %s; nav %s; robots %s</main>',
                e($request->user()?->email ?? 'nobody'),
                e($navigation),
                e($applicationPage?->robots ?? ''),
            )),
        ]);
    });
});

function applicationShellPage(Site $site, ?Blueprint $type = null): Page
{
    $type ??= resolve(BlueprintCreator::class)->applicationPageType('account', 'Account');

    return Page::factory()->site($site)->type($type)->withTranslations(slug: 'account-shell')->create(['meta' => null]);
}

it('renders the host route inside the theme layout file as the signed-in user', function (): void {
    applicationShellPage($this->site);
    $user = User::factory()->createOne();
    app()->instance(ApplicationNavigationResolver::class, new class implements ApplicationNavigationResolver
    {
        public function resolve(ApplicationAreaData $area, Site $site, Language $language, Request $request): array
        {
            return [new ApplicationNavigationItemData(label: 'Orders', url: '/test-account', active: true)];
        }
    });

    $response = $this->actingAs($user)->get('http://localhost/test-account');

    $response->assertOk()
        ->assertSee('Signed in as ' . $user->email, false)
        ->assertSee('nav Orders', false)
        ->assertSee('robots noindex,nofollow', false)
        // The default capell::app layout file wraps the slot in a full themed document.
        ->assertSee('<!DOCTYPE html>', false)
        ->assertSee('capell-default-theme', false)
        ->assertHeader('X-Robots-Tag', 'noindex,nofollow');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Vary'))->toContain('Cookie');

    // Throws, failing the test, when the HTML carries an authoring marker or editor script.
    AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response->baseResponse);
});

it('marks the request as private for the HTML cache', function (): void {
    applicationShellPage($this->site);
    $marked = null;
    Route::middleware(['web', 'frontend.application:account'])->get('/test-account-marker', function (Request $request) use (&$marked): string {
        $marked = $request->attributes->get(PrivateFrontendResponse::ATTRIBUTE);

        return 'ok';
    });

    $this->get('http://localhost/test-account-marker')->assertOk();

    expect($marked)->toBeTrue();
});

it('refuses a shell page whose type may be cached', function (): void {
    $cacheable = resolve(BlueprintCreator::class)->applicationPageType('account', 'Account');
    $cacheable->update(['meta' => [...$cacheable->meta ?? [], 'cache_time' => CacheTime::Daily->value]]);
    applicationShellPage($this->site, $cacheable->refresh());

    $response = $this->get('http://localhost/test-account');

    $response->assertStatus(503)
        ->assertSee('set to be cached', false)
        ->assertDontSee('account-content', false);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('shows the unavailable response when the area has no shell page', function (): void {
    $response = $this->get('http://localhost/test-account');

    $response->assertStatus(503)
        ->assertSee('needs a published page of type', false)
        ->assertDontSee('account-content', false);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('leaves guests on normal CMS pages untouched', function (): void {
    applicationShellPage($this->site);

    $response = $this->get('http://localhost/');

    $response->assertOk()->assertHeaderMissing('X-Robots-Tag');
    expect($response->headers->get('Cache-Control') ?? '')->not->toContain('no-store');
});

it('forces application page types to never cache, list or get critical CSS', function (): void {
    $type = resolve(BlueprintCreator::class)->applicationPageType('portal', 'Portal');
    $type->update(['meta' => [...$type->meta ?? [], 'cache_time' => CacheTime::Daily->value, 'listable' => true]]);

    $type = resolve(BlueprintCreator::class)->applicationPageType('portal', 'Portal');

    expect($type->cache_time)->toBe(CacheTime::Never)
        ->and($type->meta['listable'])->toBeFalse()
        ->and($type->meta['critical_css'])->toBeFalse();
});

it('keeps callable kernel guard steps when it swaps in the shell page step', function (): void {
    $guard = fn (mixed $work, Closure $next): mixed => $next($work);
    config()->set('frontend.kernel.steps', [SiteResolveStep::class, $guard, PageResolveStep::class, LayoutResolverStep::class]);

    expect(ResolveApplicationPageContext::steps())->toBe([SiteResolveStep::class, $guard, ApplicationPageResolveStep::class, LayoutResolverStep::class]);
});

it('runs the shell page step after the site and locale steps when no page step is configured', function (): void {
    config()->set('frontend.kernel.steps', [SiteResolveStep::class, ApplyLocaleStep::class, LayoutResolverStep::class]);

    expect(ResolveApplicationPageContext::steps())->toBe([SiteResolveStep::class, ApplyLocaleStep::class, ApplicationPageResolveStep::class, LayoutResolverStep::class]);
});
