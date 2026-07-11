<?php

declare(strict_types=1);

use Capell\Frontend\Support\Links\PublicLinkResolver;
use Capell\Frontend\Support\Links\PublicRouteAliasRegistry;

it('resolves safe public links through registered aliases', function (): void {
    $aliases = new PublicRouteAliasRegistry;
    $aliases->register('docs', fn (): string => '/docs');

    $resolver = new PublicLinkResolver($aliases);

    expect($resolver->resolve([
        'route' => 'docs',
        'anchor' => 'install',
        'query' => ['filter' => 'cms'],
    ]))->toBe('/docs?filter=cms#install');
});

it('filters unsafe public hrefs', function (?string $href, string $expected): void {
    $resolver = new PublicLinkResolver(new PublicRouteAliasRegistry);

    expect($resolver->safeHref($href))->toBe($expected);
})->with([
    'empty' => ['', '#'],
    'relative' => ['/features', '/features'],
    'protocol relative' => ['//example.com', '#'],
    'javascript' => ['javascript:alert(1)', '#'],
    'https' => ['https://example.com', 'https://example.com'],
    'mailto' => ['mailto:hello@example.com', 'mailto:hello@example.com'],
]);
