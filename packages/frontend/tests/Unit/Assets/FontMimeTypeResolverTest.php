<?php

declare(strict_types=1);

use Capell\Frontend\Support\Font\FontMimeTypeResolver;

it('resolves known font mime blueprints and defaults safely', function (): void {
    expect(FontMimeTypeResolver::resolve('woff2'))->toBe('font/woff2')
        ->and(FontMimeTypeResolver::resolve('woff'))->toBe('font/woff')
        ->and(FontMimeTypeResolver::resolve('ttf'))->toBe('font/ttf')
        ->and(FontMimeTypeResolver::resolve('otf'))->toBe('font/otf')
        ->and(FontMimeTypeResolver::resolve('unknown'))->toBe('application/octet-stream');
});

it('resolves CSS font file type hints from URLs and local paths', function (): void {
    $resolver = new FontMimeTypeResolver;

    expect($resolver->getFontFileType('https://cdn.example.test/fonts/inter.woff2?v=1'))->toBe('woff2')
        ->and($resolver->getFontFileType('/fonts/inter.woff'))->toBe('woff')
        ->and($resolver->getFontFileType('/fonts/inter.ttf'))->toBe('truetype')
        ->and($resolver->getFontFileType('/fonts/inter.eot'))->toBe('embedded-opentype')
        ->and($resolver->getFontFileType('/fonts/inter.otf'))->toBe('opentype')
        ->and($resolver->getFontFileType('/fonts/inter.svg#inter'))->toBe('svg')
        ->and($resolver->getFontFileType('/fonts/inter.unknown'))->toBe('application/octet-stream');
});
