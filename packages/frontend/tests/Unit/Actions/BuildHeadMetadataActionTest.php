<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildHeadMetadataAction;
use Capell\Frontend\Support\Render\HeadMetadataRegistry;

it('omits default URLs when the canonical URL is empty', function (): void {
    $metadata = BuildHeadMetadataAction::run('Title', null, '', 'Site', [], false);
    expect($metadata->meta)->not->toHaveKey('og:url')
        ->and($metadata->graph[1])->not->toHaveKey('url');
});

it('merges restrictive robots across package registrations and page metadata', function (): void {
    $registry = new HeadMetadataRegistry;
    $registry->register('robots', 'noarchive, nofollow');
    $registry->register('robots', 'index, follow, nosnippet');

    app()->instance(HeadMetadataRegistry::class, $registry);
    $metadata = BuildHeadMetadataAction::run('Title', null, null, null, ['noindex'], false);
    expect($metadata->meta['robots'])->toBe('noindex, noarchive, nofollow, nosnippet');
});

it('applies the public leak policy to values contributed by packages', function (): void {
    $registry = new HeadMetadataRegistry;
    $registry->register('og:description', 'Reset with token=abcdef123456');
    $registry->register('jsonld:WebPage', ['@type' => 'WebPage', 'name' => 'Title', 'model_id' => 7, 'editor_url' => 'https://example.test/admin/p/7']);

    app()->instance(HeadMetadataRegistry::class, $registry);
    $metadata = BuildHeadMetadataAction::run('Title', null, 'https://example.test/page', 'Site', [], false);

    expect($metadata->meta)->not->toHaveKey('og:description')
        ->and($metadata->meta['og:title'])->toBe('Title')
        ->and(json_encode($metadata->graph, JSON_THROW_ON_ERROR))->not->toContain('model_id')->not->toContain('editor_url')->toContain('Title');
});
