<?php

declare(strict_types=1);

use Capell\Frontend\Support\Render\HeadMetadataCollector;
use Illuminate\Support\Facades\Log;

it('collects managed metadata even when another attribute is present', function (string $html, string $key, string $value): void {
    $collector = new HeadMetadataCollector;

    expect($collector->collect($html))->toBe('')
        ->and($collector->values())->toHaveKey($key, $value);
})->with([
    ['<meta name="unrelated" property="og:title" content="Example">', 'og:title', 'Example'],
    ['<meta name="ROBOTS" property="unrelated" content="noindex">', 'robots', 'noindex'],
    ['<meta name="unrelated" property="OG:DESCRIPTION" content="A &amp; B">', 'og:description', 'A & B'],
]);

it('collects JSON-LD with unrelated Capell data markers', function (string $marker): void {
    $collector = new HeadMetadataCollector;
    $html = '<script type="application/ld+json" ' . $marker . '>{"@type":"WebPage","name":"Replacement"}</script>';

    expect($collector->collect($html))->toBe('')
        ->and($collector->values())->toHaveKey('jsonld:WebPage', ['@type' => 'WebPage', 'name' => 'Replacement']);
})->with(['data-capell-other', 'data-capell-agent-schema-extra', 'data-capell-widget']);

it('preserves a body with a long unquoted attribute', function (): void {
    $html = '<body><div data-value=' . str_repeat('x', 50000) . '>Content survives</div></body>';
    expect((new HeadMetadataCollector)->collect($html))->toBe($html);
});

it('leaves inert template and noscript contents untouched', function (string $element): void {
    $html = '<' . $element . '><meta name="robots" content="index"></' . $element . '>';
    $collector = new HeadMetadataCollector;
    expect($collector->collect($html))->toBe($html)->and($collector->values())->toBe([]);
})->with(['template', 'noscript']);

it('returns the original html and reports a regex failure', function (): void {
    $previous = ini_set('pcre.backtrack_limit', '1');
    $html = '<div>Content survives</div>';
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'Backtrack limit exhausted'));
    try {
        expect((new HeadMetadataCollector)->collect($html))->toBe($html);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $previous);
    }
});

it('keeps metadata inside a template containing styles', function (): void {
    $html = '<template><style>.example { color: red; }</style><meta name="robots" content="index"></template>';
    $collector = new HeadMetadataCollector;
    expect($collector->collect($html))->toBe($html)->and($collector->values())->toBe([]);
});

it('keeps metadata within nested templates', function (): void {
    $html = '<template><template>Inner</template><meta name="robots" content="index"></template>';
    $collector = new HeadMetadataCollector;
    expect($collector->collect($html))->toBe($html)->and($collector->values())->toBe([]);
});
