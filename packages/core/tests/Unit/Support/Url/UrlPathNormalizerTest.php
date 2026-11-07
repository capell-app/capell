<?php

declare(strict_types=1);

use Capell\Core\Support\Url\UrlPathNormalizer;

it('strips trailing index.php', function (): void {
    expect(UrlPathNormalizer::stripIndexPhp('/foo/index.php'))->toBe('/foo/');
    expect(UrlPathNormalizer::stripIndexPhp('/index.php'))->toBe('/');
    expect(UrlPathNormalizer::stripIndexPhp('/foo'))->toBe('/foo');
});

it('joins a domain prefix to a path without doubling slashes', function (): void {
    expect(UrlPathNormalizer::joinPrefix('/blog', '/article'))->toBe('/blog/article');
    expect(UrlPathNormalizer::joinPrefix('/blog/', '/article'))->toBe('/blog/article');
    expect(UrlPathNormalizer::joinPrefix('', '/article'))->toBe('/article');
    expect(UrlPathNormalizer::joinPrefix('/blog', '/'))->toBe('/blog');
});

it('removes a domain prefix from a path', function (): void {
    expect(UrlPathNormalizer::stripPrefix('/blog/article', '/blog'))->toBe('/article');
    expect(UrlPathNormalizer::stripPrefix('/article', '/blog'))->toBe('/article');
});

it('gives equivalent percent-encodings of a path one spelling', function (): void {
    $stored = '/EV%20Show%20Plates:%20Display-Only';

    expect(UrlPathNormalizer::canonicalEncoding('/EV%20Show%20Plates%3A%20Display-Only'))->toBe($stored)
        ->and(UrlPathNormalizer::canonicalEncoding('/EV%20Show%20Plates%3a%20Display-Only'))->toBe($stored)
        ->and(UrlPathNormalizer::canonicalEncoding('/EV Show Plates: Display-Only'))->toBe($stored)
        ->and(UrlPathNormalizer::canonicalEncoding('/caf%c3%a9/a%2Fb'))->toBe('/caf%C3%A9/a%2Fb')
        ->and(UrlPathNormalizer::canonicalEncoding('/about-us'))->toBe('/about-us');
});
