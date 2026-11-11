<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheTime;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\ResolveFrontendResourcePlanAction;
use Capell\Frontend\Data\Assets\ExternalResourceSourceData;
use Capell\Frontend\Data\Assets\FrontendResourceActivationData;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\FrontendResourcePlacement;
use Capell\Frontend\Enums\ReferrerPolicy;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\DefaultFrontendResourcePlanRenderer;
use Capell\Frontend\Support\Assets\StylesheetRecoveryRenderer;
use Illuminate\Foundation\Vite;

it('renders head and body resources with security attributes and vite nonce', function (): void {
    resolve(Vite::class)->useCspNonce('test-nonce');
    $style = FrontendResourceData::inlineStyle('capell-app/gallery:inline-style', 'capell-app/gallery', 'body { color: red; }</style>');
    $script = FrontendResourceData::classicScript(
        'capell-app/gallery:cdn',
        'capell-app/gallery',
        new ExternalResourceSourceData(
            'https://cdn.example.com/gallery.js?v=1',
            'sha384-YWJjZA==',
            referrerPolicy: ReferrerPolicy::NoReferrer,
        ),
        placement: FrontendResourcePlacement::BodyEnd,
        defer: false,
    );
    $inlineScript = FrontendResourceData::inlineScript('capell-app/gallery:inline-script', 'capell-app/gallery', 'window.ready = true;</script>');
    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($style),
        new FrontendResourceContributionData($script),
        new FrontendResourceContributionData($inlineScript),
    ]);

    $rendered = resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, frontendResourceContext());

    expect($rendered->headHtml)->toContain('<style nonce="test-nonce">body { color: red; }<\/style></style>')
        ->and($rendered->bodyEndHtml)->toContain('src="https://cdn.example.com/gallery.js?v=1"')
        ->and($rendered->bodyEndHtml)->toContain('integrity="sha384-YWJjZA=="')
        ->and($rendered->bodyEndHtml)->toContain('crossorigin="anonymous"')
        ->and($rendered->bodyEndHtml)->toContain('referrerpolicy="no-referrer"')
        ->and($rendered->bodyEndHtml)->not->toContain(' defer')
        ->and($rendered->bodyEndHtml)->toContain('<script nonce="test-nonce">window.ready = true;<\/script></script>')
        ->and($rendered->lazyRuntimePayload)->toBe([]);
});

it('keeps internal handles and composer package ownership out of lazy payloads', function (): void {
    $resource = FrontendResourceData::moduleScript('capell-app/gallery:runtime', 'capell-app/gallery', new ExternalResourceSourceData('https://cdn.example.com/runtime.js'));
    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($resource, [
            new FrontendResourceActivationData('widget_opaque', PresentationLoadingStrategy::Visible),
        ]),
    ]);

    $rendered = resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, frontendResourceContext());
    $json = json_encode($rendered->lazyRuntimePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('widget_opaque', 'https://cdn.example.com/runtime.js')
        ->and($json)->not->toContain('capell-app/gallery')->not->toContain('runtime"');
});

it('inlines the stylesheet recovery runtime before the stylesheet without a nonce', function (): void {
    resolve(Vite::class)->useCspNonce('recovery-nonce');
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/build/fallback/resources/css/capell/frontend.css',
        'runtime_url' => '/vendor/capell-frontend/stylesheet-recovery.js',
    ]);

    $style = FrontendResourceData::style(
        'capell-app/marketing:frontend',
        'capell-app/marketing',
        new ExternalResourceSourceData('https://cdn.example.com/build/assets/frontend-old.css'),
        criticalCssEligible: true,
    );
    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($style),
    ]);

    $rendered = resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, frontendResourceContext());
    $runtimePosition = strpos($rendered->headHtml, 'data-capell-stylesheet-recovery-runtime');
    $stylesheetPosition = strpos($rendered->headHtml, 'frontend-old.css');
    throw_if($runtimePosition === false || $stylesheetPosition === false, RuntimeException::class, 'Recovery markup is incomplete.');

    expect($rendered->headHtml)
        ->toContain('<script data-capell-stylesheet-recovery-runtime>')
        ->toContain('link[data-capell-stylesheet-recovery]')
        ->not->toContain('recovery-nonce')
        ->not->toContain('src="/vendor/capell-frontend/stylesheet-recovery.js"')
        ->toContain('<link rel="stylesheet" href="https://cdn.example.com/build/assets/frontend-old.css"')
        ->toContain('data-capell-stylesheet-recovery')
        ->toContain('data-capell-stylesheet-fallback="/build/fallback/resources/css/capell/frontend.css"')
        ->not->toContain('data-deferred-stylesheet')
        ->not->toContain('<noscript>')
        ->and($runtimePosition)
        ->toBeLessThan($stylesheetPosition);
});

function frontendResourceContext(): FrontendResourceContextData
{
    return new FrontendResourceContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );
}

it('emits identical recovery markup under different nonces and authorises it by content hash', function (): void {
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/build/fallback/frontend.css',
        'runtime_url' => '/vendor/capell-frontend/stylesheet-recovery.js',
    ]);
    $renderer = resolve(StylesheetRecoveryRenderer::class);

    resolve(Vite::class)->useCspNonce('first-response');
    $first = $renderer->runtimeTag();
    resolve(Vite::class)->useCspNonce('second-response');
    $second = $renderer->runtimeTag();

    $inline = preg_match('#<script data-capell-stylesheet-recovery-runtime>(.*)</script>#s', $first, $matches) === 1 ? $matches[1] : '';

    expect($first)->toBe($second)
        ->and($inline)->not->toBe('')
        ->and($renderer->cspHashSource())->toBe("'sha256-" . base64_encode(hash('sha256', $inline, true)) . "'");
});

it('keeps the external nonce-bearing runtime script when inlining is disabled', function (): void {
    resolve(Vite::class)->useCspNonce('external-nonce');
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/build/fallback/frontend.css',
        'runtime_url' => '/vendor/capell-frontend/stylesheet-recovery.js',
        'inline' => false,
    ]);
    $renderer = resolve(StylesheetRecoveryRenderer::class);

    expect($renderer->runtimeTag())->toBe('<script src="/vendor/capell-frontend/stylesheet-recovery.js" nonce="external-nonce" data-capell-stylesheet-recovery-runtime></script>')
        ->and($renderer->cspHashSource())->toBeNull();
});

it('renders no nonce for a cacheable page and authorises inline resources by content hash', function (): void {
    resolve(Vite::class)->useCspNonce('per-response');
    $page = new Page;
    $blueprint = new Blueprint;
    $blueprint->setAttribute('meta', ['cache_time' => CacheTime::Hourly->value]);

    $page->setRelation('blueprint', $blueprint);
    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData(FrontendResourceData::inlineStyle('capell-app/gallery:style', 'capell-app/gallery', 'body { color: red; }')),
        new FrontendResourceContributionData(FrontendResourceData::inlineScript('capell-app/gallery:script', 'capell-app/gallery', 'window.ready = true;')),
    ]);
    $context = new FrontendResourceContextData(
        page: $page,
        site: null,
        language: null,
        layout: null,
        theme: null,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );

    $rendered = resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, $context);

    expect($rendered->headHtml . $rendered->bodyEndHtml)->not->toContain('nonce=')
        ->and($rendered->styleHashSources)->toBe(["'sha256-" . base64_encode(hash('sha256', 'body { color: red; }', true)) . "'"])
        ->and($rendered->scriptHashSources)->toContain("'sha256-" . base64_encode(hash('sha256', 'window.ready = true;', true)) . "'");
});

it('keeps nonces only when the page is positively never cached', function (?CacheTime $cacheTime, bool $loaded, bool $expectNonce): void {
    resolve(Vite::class)->useCspNonce('per-response');
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/build/fallback/frontend.css',
        'runtime_url' => '/vendor/capell-frontend/stylesheet-recovery.js',
        'inline' => false,
    ]);
    $page = new Page;

    if ($loaded) {
        $blueprint = new Blueprint;
        $blueprint->setAttribute('meta', ['cache_time' => $cacheTime?->value]);

        $page->setRelation('blueprint', $blueprint);
    }

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData(FrontendResourceData::inlineScript('capell-app/gallery:script', 'capell-app/gallery', 'window.ready = true;')),
    ]);
    $context = new FrontendResourceContextData(
        page: $page,
        site: null,
        language: null,
        layout: null,
        theme: null,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );

    $rendered = resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, $context);

    expect(str_contains($rendered->headHtml . $rendered->bodyEndHtml, 'nonce="per-response"'))->toBe($expectNonce);
})->with([
    'never cached' => [CacheTime::Never, true, true],
    'cacheable' => [CacheTime::Daily, true, false],
    'blueprint not loaded' => [null, false, false],
]);
