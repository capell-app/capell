<?php

declare(strict_types=1);

use Capell\Core\Support\Security\PublicUrlSanitizer;

it('keeps safe public urls', function (string $url): void {
    expect(PublicUrlSanitizer::sanitize($url))->toBe($url);
})->with([
    '/about',
    '#content',
    'https://example.com/path',
    'HTTP://example.com/path',
    'mailto:editor@example.com',
]);

it('rejects unsafe public urls', function (mixed $url): void {
    expect(PublicUrlSanitizer::sanitize($url))->toBeNull();
})->with([
    'javascript:alert(1)',
    ' data:text/html,<script>alert(1)</script>',
    'ftp://example.com/file',
    '//example.com/path',
    '',
    null,
]);
