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
        ['scheme' => null, 'domain' => 'example.test', 'path' => '/', 'owner' => 'wildcard'],
        ['scheme' => 'https', 'domain' => 'example.test', 'path' => '/', 'owner' => 'exact'],
        ['scheme' => 'https', 'domain' => 'example.test', 'path' => '/docs', 'owner' => 'mounted'],
    ];
    if ($reverse) {
        $entries = array_reverse($entries);
    }

    expect(StaticPageDomainMatcher::best($entries, 'https', 'example.test', '/missing')['owner'] ?? null)->toBe('exact')
        ->and(StaticPageDomainMatcher::best($entries, 'https', 'example.test', '/docs/a')['owner'] ?? null)->toBe('mounted')
        ->and(StaticPageDomainMatcher::best($entries, 'http', 'example.test', '/missing')['owner'] ?? null)->toBe('wildcard')
        ->and(StaticPageDomainMatcher::best($entries, 'https', 'other.test', '/'))->toBeNull();
})->with([false, true]);
