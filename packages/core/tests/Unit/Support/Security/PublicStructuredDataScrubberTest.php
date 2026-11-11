<?php

declare(strict_types=1);

use Capell\Core\Support\Security\PublicOutputLeakPolicy;
use Capell\Core\Support\Security\PublicStructuredDataScrubber;

beforeEach(function (): void {
    $this->scrubber = new PublicStructuredDataScrubber(new PublicOutputLeakPolicy);
});

it('drops authoring and credential keys from nested JSON-LD while keeping the rest of the node', function (): void {
    $graph = $this->scrubber->scrubGraph([
        '@type' => 'WebPage',
        'name' => 'Pricing',
        'model_id' => 12,
        'editor_url' => 'https://example.test/admin/pages/12/edit',
        'author' => ['@type' => 'Person', 'name' => 'Sam', 'api_key' => 'abc', 'signed_url' => 'https://x.test/?signature=1'],
        'breadcrumb' => [['name' => 'Home', 'field_path' => 'a.b']],
    ]);

    expect($graph)->toBe([
        '@type' => 'WebPage',
        'name' => 'Pricing',
        'author' => ['@type' => 'Person', 'name' => 'Sam'],
        'breadcrumb' => [['name' => 'Home']],
    ]);
});

it('drops string values that carry authoring or credential markers', function (): void {
    $graph = $this->scrubber->scrubGraph([
        'name' => 'Pricing',
        'url' => '/admin/pages',
        'description' => 'Call with token=abcdef123456 now',
        'sameAs' => 'https://example.test/pricing',
    ]);

    expect($graph)->toBe(['name' => 'Pricing', 'sameAs' => 'https://example.test/pricing']);
});

it('drops leaking meta entries and keeps ordinary ones', function (): void {
    expect($this->scrubber->scrubMeta([
        'og:title' => 'Pricing',
        'og:url' => '/filament/panel',
        'robots' => 'index, follow',
        'token' => 'x',
    ]))->toBe(['og:title' => 'Pricing', 'robots' => 'index, follow']);
});
