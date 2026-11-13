<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Privacy\PrivacyRuntimeRenderHook;
use Capell\Frontend\Support\Privacy\PrivacyRuntimeScript;
use Capell\Frontend\Support\Render\FrontendHookRegistrar;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;

/** @return array<string, mixed> */
function privacyRuntimeConfig(string $html): array
{
    preg_match('#<script type="application/json" id="capell-privacy-runtime-config">(.*?)</script>#s', $html, $match);
    $decoded = json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

function renderPrivacyRuntimeHook(): string
{
    return resolve(PrivacyRuntimeRenderHook::class)->render(new RenderHookContext(location: RenderHookLocation::HeadOpen->value, item: null));
}

it('loads window.CapellPrivacy at the top of the head, before every other script', function (): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false]);
    Cache::flush();
    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost', 'scheme' => 'http', 'path' => null, 'default' => true,
    ])->create();
    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create(['meta' => null]);
    resolve(FrontendHookRegistrar::class)->contribute(RenderHookLocation::HeadOpen, new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<script src="/consent-manager.js"></script>';
        }
    }, owner: 'capell-app/consent-manager-fixture', key: 'consent-manager-runtime', priority: 1);

    $response = $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost']);
    $response->assertOk();

    $html = (string) $response->getContent();
    $head = substr($html, 0, (int) strpos($html, '</head>'));
    $runtimeUrl = resolve(PrivacyRuntimeScript::class)->url();
    preg_match_all('#<script\b[^>]*\bsrc="([^"]+)"#', $head, $scripts);

    expect($scripts[1][0] ?? null)->toBe('/consent-manager.js')
        ->and($scripts[1][1] ?? null)->toBe($runtimeUrl)
        ->and($head)->toContain('id="capell-privacy-runtime-config"')
        ->and(strpos($head, $runtimeUrl))->toBeLessThan(strpos($head, '<title>'));
});

it('leaves the runtime out when it is switched off', function (): void {
    config(['capell.privacy.consent.runtime' => false]);
    app()->forgetInstance(RenderHookRegistry::class);
    app()->forgetInstance(FrontendHookRegistrar::class);

    expect(resolve(RenderHookRegistry::class)->renderAll(RenderHookLocation::HeadOpen))->not->toContain('capell-privacy-runtime');
});

it('renders the same configuration for every visitor, with no nonce or request state', function (): void {
    config([
        'capell.privacy.consent.defaults' => ['functional' => 'granted', 'preferences' => 'denied', 'analytics' => 'granted', 'marketing' => 'undecided'],
        'capell.privacy.consent.purposes' => ['youtube' => 'marketing'],
    ]);
    resolve(Vite::class)->useCspNonce('per-request-nonce');
    $anonymous = renderPrivacyRuntimeHook();

    request()->headers->set('Sec-GPC', '1');
    request()->headers->set('DNT', '1');
    request()->cookies->set('capell_session', 'visitor-session');

    $optedOut = renderPrivacyRuntimeHook();

    expect($optedOut)->toBe($anonymous)
        ->and($anonymous)->not->toContain('nonce')
        ->not->toContain('per-request-nonce')
        ->not->toContain('visitor-session')
        ->and(privacyRuntimeConfig($anonymous))->toBe([
            'categories' => ['essential', 'functional', 'preferences', 'analytics', 'marketing'],
            'purposes' => ['youtube' => 'marketing'],
            'defaults' => ['essential' => true, 'functional' => true, 'preferences' => false, 'analytics' => true, 'marketing' => null],
            'optOutCategories' => ['analytics', 'marketing'],
        ])
        ->and($anonymous)->toContain('<script src="' . resolve(PrivacyRuntimeScript::class)->url() . '"></script>');
});

it('keeps an empty purpose map a JSON object and escapes configured purposes', function (): void {
    expect(renderPrivacyRuntimeHook())->toContain('"purposes":{}');

    config(['capell.privacy.consent.purposes' => ['</script><script>alert(1)</script>' => 'marketing']]);
    $html = renderPrivacyRuntimeHook();

    expect($html)->not->toContain('<script>alert(1)')
        ->and(privacyRuntimeConfig($html)['purposes'])->toBe(['</script><script>alert(1)</script>' => 'marketing']);
});

it('serves the runtime as a cacheable script with no cookie', function (): void {
    $script = resolve(PrivacyRuntimeScript::class);

    $response = $this->get($script->url());

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('max-age=31536000')->toContain('public')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->getContent())->toBe($script->contents())
        ->and($script->url())->toStartWith('/_capell/privacy-runtime?v=');
});

it('sends any other version to the current script without caching the redirect', function (string $suffix): void {
    $response = $this->get('/_capell/privacy-runtime' . $suffix);

    $response->assertFound();

    expect($response->headers->get('Location'))->toEndWith(resolve(PrivacyRuntimeScript::class)->url())
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([]);
})->with(['missing version' => [''], 'stale version' => ['?v=0123456789abcdef'], 'array version' => ['?v[]=x']]);
