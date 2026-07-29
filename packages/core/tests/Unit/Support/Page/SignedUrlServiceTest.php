<?php

declare(strict_types=1);

use Capell\Core\Support\Page\SignedUrlService;

it('keeps the draft marker in the path when the url has no path', function (): void {
    expect(SignedUrlService::signedDraft('https://example.com', 42))
        ->toStartWith('https://example.com/{42}?signature=');
});

it('keeps the draft marker in the path when the url is a bare host with a trailing slash', function (): void {
    expect(SignedUrlService::signedDraft('https://example.com/', 42))
        ->toStartWith('https://example.com/{42}?signature=');
});
