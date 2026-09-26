<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainUrlAction;
use Capell\Core\Models\SiteDomain;

it('resolves extension callback paths against the current site domain', function (
    string $mountPath,
    string $callbackPath,
    string $expectedUrl,
): void {
    $siteDomain = new SiteDomain;
    $siteDomain->forceFill([
        'scheme' => 'https',
        'domain' => 'blog.example.test',
        'path' => $mountPath,
    ]);

    expect(ResolveSiteDomainUrlAction::run($siteDomain, $callbackPath))->toBe($expectedUrl);
})->with([
    'root-mounted root-relative path' => ['/', '/tags/example', 'https://blog.example.test/tags/example'],
    'path-mounted root-relative path' => ['/en', '/archive/2026/09', 'https://blog.example.test/en/archive/2026/09'],
    'path-mounted relative path' => ['/en', 'feed.xml?format=rss', 'https://blog.example.test/en/feed.xml?format=rss'],
]);

it('preserves absolute extension callback URLs', function (): void {
    $siteDomain = new SiteDomain;
    $siteDomain->forceFill([
        'scheme' => 'https',
        'domain' => 'blog.example.test',
        'path' => '/en',
    ]);
    $url = 'https://blog.example.test/en/tags/example?preview=0';

    expect(ResolveSiteDomainUrlAction::run($siteDomain, $url))->toBe($url);
});
