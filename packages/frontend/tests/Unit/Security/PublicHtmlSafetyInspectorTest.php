<?php

declare(strict_types=1);

use Capell\Frontend\Data\PublicHtmlSafetyDetectionData;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;

it('detects frontend authoring markers in public html', function (string $html): void {
    $inspector = new PublicHtmlSafetyInspector;

    expect($inspector->containsAuthoringSurface($html))->toBeTrue();
})->with([
    '<div data-capell-authoring="page:1">Edit</div>',
    '<button data-capell-editor-url="https://example.com/admin/pages/1/edit?signature=abc">Edit</button>',
    '<div class="capell-editor">Edit</div>',
    '<script type="application/json">{"field_path":"content.blocks.0.title"}</script>',
    '<script type = "application/json">{"model_id":42}</script>',
    '<script>window.capellPreview = {"model_id":42}</script>',
    '<script>window.capellPreview = {model_id: 42}</script>',
    '<script>window.capellPreview = {"modelId":42,"signedEditorUrl":"/admin/pages/1/edit"}</script>',
    "<script>window.capellPreview = {'field_path': 'content.blocks.0.title'}</script>",
    '<div data-state="{&quot;model_id&quot;:42,&quot;field_path&quot;:&quot;title&quot;}"></div>',
    '<div data-state="{&quot;signedUrl&quot;:&quot;/admin/pages/1/edit&quot;}"></div>',
    '<div x-data=\'{"editor_url":"/admin/pages/1/edit"}\'></div>',
    '<div data-model-id="42" data-field-path="title"></div>',
    '<script src="/vendor/capell-authoring/toolbar.js"></script>',
    '<style>.capell-editor-toolbar { display: block; }</style>',
]);

it('allows ordinary public html that mentions editing concepts as content', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = <<<'HTML'
        <article>
            <h1>Editorial calendar</h1>
            <p>Our editors explain how the article was produced.</p>
            <a href="/about/editorial-policy">Editorial policy</a>
        </article>
    HTML;

    expect($inspector->containsAuthoringSurface($html))->toBeFalse();
});

it('allows generic signed url JSON keys for non-admin public URLs', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = <<<'HTML'
        <script type="application/json">
            {"signed_url": "https://cdn.example.test/media/image.jpg?signature=public-cdn"}
        </script>
    HTML;

    expect($inspector->containsAuthoringSurface($html))->toBeFalse();
});

it('detects generic signed url JSON keys when they contain admin URLs', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<div data-state="{&quot;signedUrl&quot;:&quot;/admin/pages/1/edit&quot;}"></div>';

    expect($inspector->containsAuthoringSurface($html))->toBeTrue();
});

it('detects unquoted generic signed url JavaScript keys when they contain admin URLs', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<script>window.capellPreview = { signedUrl: "/admin/pages/1/edit" }</script>';

    expect($inspector->containsAuthoringSurface($html))->toBeTrue();
});

it('allows unquoted generic signed url JavaScript keys for non-admin public URLs', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<script>window.asset = { signedUrl: "https://cdn.example.test/media/image.jpg?signature=public-cdn" }</script>';

    expect($inspector->containsAuthoringSurface($html))->toBeFalse();
});

it('allows code samples that mention authoring marker names as content', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = <<<'HTML'
        <article>
            <h1>Frontend authoring API</h1>
            <pre><code>&lt;div data-model-id="42"&gt;Example&lt;/div&gt;</code></pre>
            <p>JSON examples may include "model_id" or "field_path" in prose.</p>
        </article>
    HTML;

    expect($inspector->containsAuthoringSurface($html))->toBeFalse();
});

it('reports the matched authoring marker', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $detection = $inspector->detectAuthoringSurface('<div data-capell-authoring="page:1">Edit</div>');
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('authoring_marker')
        ->and($detection->matched)->toBe('data-capell-authoring')
        ->and($detection->reason)->toBe('Public HTML contains an authoring marker.');
});

it('reports signed admin URLs as authoring surface', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $detection = $inspector->detectAuthoringSurface('<a href="/admin/pages/1/edit?signature=abc">Edit</a>');
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url')
        ->and($detection->matched)->toBe('/admin/...signature=')
        ->and($detection->reason)->toBe('Public HTML contains a signed admin URL.');
});

it('reports signed admin URLs using the configured admin path', function (): void {
    config()->set('capell-admin.path', 'cms');

    $inspector = new PublicHtmlSafetyInspector;

    $detection = $inspector->detectAuthoringSurface('<a href="/cms/pages/1/edit?signature=abc">Edit</a>');
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url')
        ->and($detection->matched)->toBe('/cms/...signature=');
});

it('reports signed admin URLs with escaped query separators', function (): void {
    config()->set('capell-admin.path', 'cms');

    $inspector = new PublicHtmlSafetyInspector;

    $detection = $inspector->detectAuthoringSurface('<a href="/cms/pages/1/edit?expires=123&amp;signature=abc">Edit</a>');
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url');
});

it('reports signed admin URLs with JSON escaped slashes and ampersands', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $detection = $inspector->detectAuthoringSurface('<script>window.url="\\/admin\\/pages\\/1\\/edit?expires=123\\u0026signature=abc"</script>');
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url');
});

it('reports signed admin URLs with unicode escaped signature text', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $detection = $inspector->detectAuthoringSurface('<script>window.url="\\/admin\\/pages\\/1\\/edit?expires=123\\u0026\\u0073\\u0069\\u0067\\u006e\\u0061\\u0074\\u0075\\u0072\\u0065=abc"</script>');
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url');
});

it('reports signed admin URLs with escaped slashes in large public html', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<!DOCTYPE html><html><head>'
        . str_repeat('<style>.utility::before { content: "\\uE001"; color: #123456; background: #ffffff; }</style>', 5000)
        . '</head><body><script>window.url="\\/admin\\/pages\\/1\\/edit?expires=123\\u0026signature=abc"</script></body></html>';

    $detection = $inspector->detectAuthoringSurface($html);
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url');
});

it('reports signed admin URLs with unicode escaped signature text in large public html', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<!DOCTYPE html><html><head>'
        . str_repeat('<style>.utility::before { content: "\\uE001"; color: #123456; background: #ffffff; }</style>', 5000)
        . '</head><body><script>window.url="\\/admin\\/pages\\/1\\/edit?expires=123\\u0026\\u0073\\u0069\\u0067\\u006e\\u0061\\u0074\\u0075\\u0072\\u0065=abc"</script></body></html>';

    $detection = $inspector->detectAuthoringSurface($html);
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url');
});

it('reports signed admin URLs with unicode escaped admin paths in large public html', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<!DOCTYPE html><html><head>'
        . str_repeat('<style>.utility::before { content: "\\uE001"; color: #123456; background: #ffffff; }</style>', 5000)
        . '</head><body><script>window.url="\\/\\u0061dmin\\/pages\\/1\\/edit?expires=123\\u0026signature=abc"</script></body></html>';

    $detection = $inspector->detectAuthoringSurface($html);
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url');
});

it('reports signed admin URLs with unicode escaped signature text beyond the first scan window in large public html', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<!DOCTYPE html><html><head>'
        . str_repeat('<style>.utility::before { content: "\\uE001"; color: #123456; background: #ffffff; }</style>', 5000)
        . '</head><body><script>window.url="\\/admin\\/pages\\/1\\/edit?'
        . str_repeat('state=public\\u0026', 400)
        . '\\u0073\\u0069\\u0067\\u006e\\u0061\\u0074\\u0075\\u0072\\u0065=abc"</script></body></html>';

    $detection = $inspector->detectAuthoringSurface($html);
    assert($detection instanceof PublicHtmlSafetyDetectionData);

    expect($detection)
        ->not->toBeNull()
        ->and($detection->category)->toBe('signed_admin_url');
});

it('ignores json escaped surrogate pairs that cannot be decoded individually', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<script>window.label="\\ud83d\\ude80 public launch"</script>';

    expect($inspector->containsAuthoringSurface($html))->toBeFalse();
});

it('does not scan non-script prose for marker keys', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = <<<'HTML'
        <pre><code>{"field_path": "example only"}</code></pre>
    HTML;

    expect($inspector->containsAuthoringSurface($html))->toBeFalse();
});

it('returns no detection for ordinary public html', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    expect($inspector->detectAuthoringSurface('<article><h1>News</h1></article>'))->toBeNull();
});

it('scans large public html without expanding json escape variants when none are present', function (): void {
    $inspector = new PublicHtmlSafetyInspector;

    $html = '<!DOCTYPE html><html><head>'
        . str_repeat('<style>.utility::before { content: "\\uE001"; color: #123456; background: #ffffff; }</style>', 5000)
        . '</head><body><main><h1>Public page</h1></main></body></html>';

    expect($inspector->containsAuthoringSurface($html))->toBeFalse();
});

describe('undocumented data-capell-* runtime attributes', function (): void {
    // A render hook / extension emits HTML at runtime, bypassing the
    // Blade-source arch test, so an undocumented `data-capell-*` attribute could
    // smuggle authoring data into public output. The scanner must reject it.
    it('flags undocumented data-capell-* attributes a render hook could emit', function (string $html): void {
        $inspector = new PublicHtmlSafetyInspector;

        expect($inspector->containsAuthoringSurface($html))->toBeTrue();
    })->with([
        'internal id' => '<div data-capell-internal-id="42">Promo</div>',
        'settings leak' => '<section data-capell-secret-settings=\'{"apiKey":"x"}\'>Promo</section>',
        'self-closing' => '<img data-capell-asset-ref="7"/>',
        'bare attribute' => '<div data-capell-debug>Promo</div>',
        'uppercase variant' => '<div DATA-CAPELL-Editor-State="x">Promo</div>',
        // Leak listed before an allowed attribute on the same tag: a greedy
        // single-match-per-tag scan would miss this.
        'leak before allowed attribute' => '<div data-capell-secret="x" data-capell-widget-runtime="y">Promo</div>',
    ]);

    it('allows the documented public-safe runtime attributes', function (string $html): void {
        $inspector = new PublicHtmlSafetyInspector;

        expect($inspector->containsAuthoringSurface($html))->toBeFalse();
    })->with([
        'widget runtime' => '<div data-capell-widget-runtime="carousel" data-capell-widget-assets="[]">x</div>',
        'interaction' => '<button data-capell-interaction data-capell-interaction-event="click" data-capell-interaction-id="cta">Go</button>',
        // The insights package renders a public, anonymous-facing consent banner
        // and analytics tracker. These data-capell-insights-* attributes are
        // client-side consent/analytics wiring, not an authoring surface.
        'insights consent banner' => '<section data-capell-insights-consent-banner hidden><button data-capell-insights-consent-action="accept">Accept</button></section>',
        'insights tracker' => '<a data-capell-insights-tracker data-capell-insights-label="cta" data-capell-insights-location="hero">Go</a>',
    ]);

    it('allows undocumented attribute names that only appear inside code samples', function (): void {
        $inspector = new PublicHtmlSafetyInspector;

        $html = <<<'HTML'
            <article>
                <p>Add the attribute to your tag:</p>
                <pre><code>&lt;div data-capell-internal-id="42"&gt;&lt;/div&gt;</code></pre>
                <code>data-capell-secret</code>
            </article>
        HTML;

        expect($inspector->containsAuthoringSurface($html))->toBeFalse();
    });

    it('does not flag the attribute name when it only appears in body text, not as an attribute', function (): void {
        $inspector = new PublicHtmlSafetyInspector;

        $html = '<p>We renamed data-capell-foo last release.</p>';

        expect($inspector->containsAuthoringSurface($html))->toBeFalse();
    });
});
