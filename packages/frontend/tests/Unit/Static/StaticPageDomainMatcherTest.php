<?php

declare(strict_types=1);

use Capell\Frontend\Support\Static\StaticPageDomainMatcher;

it('matches the complete route including default ports and path boundaries', function (?string $entryScheme, ?int $entryPort, string $requestScheme, int $requestPort, string $path, bool $matches): void {
    $entry = ['scheme' => $entryScheme, 'domain' => 'EXAMPLE.test', 'port' => $entryPort, 'path' => '/docs/'];

    expect(StaticPageDomainMatcher::matches($entry, $requestScheme, 'example.test', $path, $requestPort))->toBe($matches);
})->with([
    'https default' => ['https', null, 'https', 443, '/docs/a', true],
    'wrong port' => ['https', null, 'https', 8443, '/docs/a', false],
    'explicit port' => ['https', 8443, 'https', 8443, '/docs/a', true],
    'wrong scheme' => ['https', null, 'http', 80, '/docs/a', false],
    'wildcard http default' => [null, null, 'http', 80, '/docs/a', true],
    'wildcard https default' => [null, null, 'https', 443, '/docs/a', true],
    'wildcard non-default rejected' => [null, null, 'http', 8443, '/docs/a', false],
    'wildcard explicit port' => [null, 8443, 'http', 8443, '/docs/a', true],
    'exact mount' => ['https', null, 'https', 443, '/docs', true],
    'path boundary' => ['https', null, 'https', 443, '/docs-other', false],
]);

it('prefers mounted routes then explicit constraints independent of manifest order', function (bool $reverse): void {
    $entries = [
        ['scheme' => null, 'domain' => 'example.test', 'port' => null, 'path' => '/', 'owner' => 'wildcard'],
        ['scheme' => 'https', 'domain' => 'example.test', 'port' => null, 'path' => '/', 'owner' => 'exact'],
        ['scheme' => 'https', 'domain' => 'example.test', 'port' => null, 'path' => '/docs', 'owner' => 'mounted'],
    ];
    if ($reverse) {
        $entries = array_reverse($entries);
    }

    expect(StaticPageDomainMatcher::best($entries, 'https', 'example.test', '/missing')['owner'] ?? null)->toBe('exact')
        ->and(StaticPageDomainMatcher::best($entries, 'https', 'example.test', '/docs/a')['owner'] ?? null)->toBe('mounted')
        ->and(StaticPageDomainMatcher::best($entries, 'http', 'example.test', '/missing')['owner'] ?? null)->toBe('wildcard')
        ->and(StaticPageDomainMatcher::best($entries, 'https', 'other.test', '/'))->toBeNull();
})->with([false, true]);

it('rejects routes without a recorded port instead of guessing the original origin', function (): void {
    $entry = ['scheme' => 'https', 'domain' => 'example.test', 'path' => '/'];

    expect(StaticPageDomainMatcher::matches($entry, 'https', 'example.test', '/', 443))->toBeFalse()
        ->and(StaticPageDomainMatcher::matches($entry, 'https', 'example.test', '/', 8443))->toBeFalse()
        ->and(StaticPageDomainMatcher::matches([...$entry, 'port' => null], 'https', 'example.test', '/', 443))->toBeTrue();
});

it('rejects routes without a recorded mount instead of expanding them to the root', function (mixed $mount): void {
    $entry = ['scheme' => 'https', 'domain' => 'example.test', 'port' => null, 'path' => $mount];

    expect(StaticPageDomainMatcher::matches($entry, 'https', 'example.test', '/outside', 443))->toBeFalse();
})->with([null, '', false]);
