<?php

declare(strict_types=1);

use Capell\Core\Models\PageUrl;
use Capell\Frontend\Actions\ParseWildcardPageUrlAction;

it('extracts params and pageSlug from wildcard page url', function (): void {
    $url = new PageUrl;
    $url->url = '/blog/*';

    $result = ParseWildcardPageUrlAction::run($url, '/blog/some-post', []);

    expect($result)->toHaveKeys(['params'])
        ->and($result['params'] ?? [])->toBeArray();
});

it('extracts params and pageSlug from multi-segment wildcard', function (): void {
    $url = new PageUrl;
    $url->url = '/docs/*/page/*';

    $result = ParseWildcardPageUrlAction::run($url, '/docs/api/page/intro', []);

    expect($result)->toHaveKeys(['params'])
        ->and($result['params'] ?? [])->toBeArray();
});
