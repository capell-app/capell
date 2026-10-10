<?php

declare(strict_types=1);

use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Tests\Support\JavascriptFixture;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

it('keeps shared frontend layout free of foundation chrome fallbacks', function (): void {
    frontendViewFixture();
    $html = Blade::render('<x-capell::layout>Public slot</x-capell::layout>');
    expect($html)->toContain('Public slot');
    expect(domCount(frontendRenderedDom($html), '//header | //footer'))->toBe(0);
});
it('renders prepared layout data without database queries or authoring output', function (): void {
    frontendViewFixture();
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $html = Blade::render('<x-capell::layout>Public slot</x-capell::layout>');
        expect($html)->toContain('Public body', 'Public slot')
            ->not->toContain('data-model-id', 'data-field-path', 'signed-editor');
        expect($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
});
it('renders branded system page content with colour-scheme support', function (): void {
    frontendViewFixture(system: true);
    $html = Blade::render('<x-capell::layout>System slot</x-capell::layout>');
    foreach (['capell-default-theme__layout', 'capell-default-theme__brand', 'capell-default-theme__content'] as $class) {
        expect(frontendHasClass($html, $class))->toBeTrue();
    }

    expect($html)->toContain('Public site', 'Public title', 'Public body', 'System slot');
    // These delivered CSS tokens preserve OS preference and explicit colour-scheme overrides.
    $styles = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/css/base/default-theme.css');
    expect($styles)->toContain('prefers-color-scheme', ':root.dark', ':root.light');
});
it('declares light and dark desktop and mobile screenshot states', function (): void {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 3) . '/docs/screenshots.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $entries = collect($manifest['entries'])->keyBy('id');

    expect($entries['frontend-published-page']['colorSchemes'] ?? null)->toBe(['light', 'dark'])
        ->and($entries['frontend-published-page']['viewport'] ?? 'desktop')->toBe('desktop')
        ->and($entries['frontend-published-page-mobile']['colorSchemes'] ?? null)->toBe(['light', 'dark'])
        ->and($entries['frontend-published-page-mobile']['viewport'] ?? null)->toBe('mobile');
});

it('exposes the shared main content render hook', function (): void {
    $fixture = frontendViewFixture();
    $hooks = resolve(RenderHookRegistry::class);
    $hooks->register(RenderHookLocation::MainContent, '<p>Contributed main content</p>');
    $hooks->register(RenderHookLocation::AfterContent, '<p>Contributed after content</p>');

    $html = Blade::render('<x-capell::layout.main :page="$page" :layout="null" :theme="[]" page-slot="Public slot" />', $fixture);
    expect($html)->toContain('Contributed main content', 'Contributed after content', 'Public slot')
        ->not->toContain('Public body');
});
it('renders shared content without lightbox behaviour', function (): void {
    frontendViewFixture();
    $html = Blade::render('<x-capell::content content="&lt;p&gt;Public content&lt;/p&gt;" />');
    expect($html)->toContain('Public content');
    expect(domCount(frontendRenderedDom($html), '//*[@data-lightbox]'))->toBe(0);
});
it('starts the generic Alpine runtime once and preserves an existing host runtime', function (bool $existing): void {
    $state = JavascriptFixture::evaluate(
        dirname(__DIR__, 3) . '/resources/js/capell-frontend.js',
        'globalThis.starts = 0; globalThis.plugins = []; globalThis.callbacks = {};
        globalThis.window = {addEventListener: (name, callback) => callbacks[name] = callback};
        globalThis.document = {readyState: "loading", addEventListener: (name, callback) => callbacks[name] = callback};
        globalThis.host = {start: () => starts++, plugin: value => plugins.push(value)};' . ($existing ? 'window.Alpine = host;' : ''),
        '(() => { const beforeLoad = starts; callbacks["alpine:init"](); callbacks.load(); callbacks.load(); return {beforeLoad, starts, plugins, preservesHost: window.Alpine === host}; })()',
        [
            'alpinejs' => 'export default {start: () => starts++, plugin: value => plugins.push(value)};',
            '@alpinejs/focus' => 'export default "focus";',
            '@alpinejs/intersect' => 'export default "intersect";',
            '@awcodes/alpine-floating-ui' => 'export default "floating";',
            './widget-runtime' => '',
        ],
    );
    expect($state)->toBe([
        'beforeLoad' => 0,
        'starts' => $existing ? 0 : 1,
        'plugins' => ['focus', 'intersect', 'floating'],
        'preservesHost' => $existing,
    ]);
})->with([false, true]);

it('does not retain the legacy vendor build asset bridge', function (): void {
    expect(dirname(__DIR__, 3) . '/src/Support/Assets/VendorBuildAssetContributor.php')->not->toBeFile();
});
