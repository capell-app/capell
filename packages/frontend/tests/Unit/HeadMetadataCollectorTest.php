<?php

declare(strict_types=1);

use Capell\Frontend\Support\Render\HeadMetadataCollector;

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
