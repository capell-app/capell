<?php

declare(strict_types=1);

use Capell\Admin\Support\SafeAdminUrl;

it('allows relative and web urls in admin links', function (?string $url): void {
    expect(SafeAdminUrl::href($url))->toBe($url);
})->with([
    '/missing-page',
    'https://example.com/missing-page',
    'http://example.com/missing-page',
]);

it('rejects unsafe admin link urls', function (?string $url): void {
    expect(SafeAdminUrl::href($url))->toBeNull();
})->with([
    null,
    '',
    '//example.com/protocol-relative',
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
]);
