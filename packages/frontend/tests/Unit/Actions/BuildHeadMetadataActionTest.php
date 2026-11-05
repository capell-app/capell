<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildHeadMetadataAction;
use Capell\Frontend\Support\Render\HeadMetadataRegistry;

it('omits default URLs when the canonical URL is empty', function (): void {
    $metadata = new BuildHeadMetadataAction(new HeadMetadataRegistry)->handle('Title', null, '', 'Site', [], false);
    expect($metadata->meta)->not->toHaveKey('og:url')
        ->and($metadata->graph[1])->not->toHaveKey('url');
});

it('merges restrictive robots across package registrations and page metadata', function (): void {
    $registry = new HeadMetadataRegistry;
    $registry->register('robots', 'noarchive, nofollow');
    $registry->register('robots', 'index, follow, nosnippet');

    $metadata = new BuildHeadMetadataAction($registry)->handle('Title', null, null, null, ['noindex'], false);
    expect($metadata->meta['robots'])->toBe('noindex, noarchive, nofollow, nosnippet');
});
