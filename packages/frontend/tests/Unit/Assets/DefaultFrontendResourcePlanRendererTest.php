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
use Capell\Frontend\Support\Assets\StylesheetRecoveryRenderer;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;

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

it('runs the stylesheet recovery runtime before critical stylesheets, which stay blocking', function (): void {
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

    // Inlined when the published runtime is readable, otherwise the external
    // tag; either way it carries the nonce and runs before the stylesheet.
    expect($rendered->headHtml)
        ->toContain('nonce="recovery-nonce" data-capell-stylesheet-recovery-runtime')
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

it('inlines the published stylesheet recovery runtime before the stylesheet so no request blocks the head', function (): void {
    $runtimeDirectory = public_path('vendor/capell-frontend-inline-test');
    File::ensureDirectoryExists($runtimeDirectory);
    File::put($runtimeDirectory . '/stylesheet-recovery.js', ';(() => { window.recoveryRuntimeLoaded = true })()' . "\n");
    File::put($runtimeDirectory . '/unsafe-recovery.js', 'document.write("</script><b>broken</b>")');
    resolve(Vite::class)->useCspNonce('recovery-nonce');
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/build/fallback/resources/css/capell/frontend.css',
        'runtime_url' => '/vendor/capell-frontend-inline-test/stylesheet-recovery.js',
    ]);

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData(FrontendResourceData::style(
            'capell-app/marketing:frontend',
            'capell-app/marketing',
            new ExternalResourceSourceData('https://cdn.example.com/build/assets/frontend-old.css'),
            criticalCssEligible: true,
        )),
    ]);

    try {
        $headHtml = resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, frontendResourceContext())->headHtml;
        $runtimePosition = strpos($headHtml, 'window.recoveryRuntimeLoaded');
        $stylesheetPosition = strpos($headHtml, 'frontend-old.css');

        expect($headHtml)
            ->toContain('<script nonce="recovery-nonce" data-capell-stylesheet-recovery-runtime>;(() => { window.recoveryRuntimeLoaded = true })()')
            ->not->toContain('src="/vendor/capell-frontend-inline-test/stylesheet-recovery.js"')
            ->and($runtimePosition)->toBeInt()->toBeLessThan($stylesheetPosition);

        config()->set('capell-frontend.stylesheet_recovery.runtime_url', '/vendor/capell-frontend-inline-test/unsafe-recovery.js');

        expect(resolve(DefaultFrontendResourcePlanRenderer::class)->render($plan, frontendResourceContext())->headHtml)
            ->toContain('<script src="/vendor/capell-frontend-inline-test/unsafe-recovery.js" nonce="recovery-nonce" data-capell-stylesheet-recovery-runtime></script>')
            ->not->toContain('<b>broken</b>');
    } finally {
        File::deleteDirectory($runtimeDirectory);
    }
});

it('uses an external recovery runtime when no CSP nonce is available', function (): void {
    $directory = public_path('vendor/capell-recovery-csp-test');
    File::ensureDirectoryExists($directory);
    File::put($directory . '/runtime.js', 'window.recovery = true;');
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/fallback.css',
        'runtime_url' => '/vendor/capell-recovery-csp-test/runtime.js',
    ]);

    try {
        $renderer = new StylesheetRecoveryRenderer(new Vite);
        expect($renderer->runtimeTag())->toContain('src="/vendor/capell-recovery-csp-test/runtime.js"')
            ->not->toContain('window.recovery = true');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('uses the replaced recovery runtime even when its modification time is unchanged', function (): void {
    $directory = public_path('vendor/capell-recovery-replace-test');
    $path = $directory . '/runtime.js';
    File::ensureDirectoryExists($directory);
    File::put($path, 'window.recovery = "old";');
    $mtime = filemtime($path);
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/fallback.css',
        'runtime_url' => '/vendor/capell-recovery-replace-test/runtime.js',
    ]);
    $vite = new Vite;
    $vite->useCspNonce('test-nonce');

    $renderer = new StylesheetRecoveryRenderer($vite);

    try {
        expect($renderer->runtimeTag())->toContain('window.recovery = "old"');
        File::put($path, 'window.recovery = "new";');
        touch($path, $mtime);
        clearstatcache(true, $path);
        expect($renderer->runtimeTag())->toContain('window.recovery = "new"')->not->toContain('window.recovery = "old"');
    } finally {
        File::deleteDirectory($directory);
    }
});
