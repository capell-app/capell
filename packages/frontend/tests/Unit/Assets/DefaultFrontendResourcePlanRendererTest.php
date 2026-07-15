<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
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
    $plan = resolve(ResolveFrontendResourcePlanAction::class)->handle([
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
    $plan = resolve(ResolveFrontendResourcePlanAction::class)->handle([
        new FrontendResourceContributionData($resource, [
            new FrontendResourceActivationData('widget_opaque', PresentationLoadingStrategy::Visible),
        ]),
    ]);

    $rendered = resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, frontendResourceContext());
    $json = json_encode($rendered->lazyRuntimePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('widget_opaque', 'https://cdn.example.com/runtime.js')
        ->and($json)->not->toContain('capell-app/gallery', 'runtime"');
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
