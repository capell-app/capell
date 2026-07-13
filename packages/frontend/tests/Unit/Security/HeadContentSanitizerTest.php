<?php

declare(strict_types=1);

use Capell\Frontend\Support\Security\HeadContentSanitizer;

it('escapes style and script terminators in css contexts', function (): void {
    $css = 'body{color:red}</style><script>alert(1)</script>';

    expect(HeadContentSanitizer::css($css))
        ->not->toContain('</style>')
        ->not->toContain('</script>')
        ->toContain('<\/style>')
        ->toContain('<\/script>');
});

it('removes unsafe css value characters', function (): void {
    expect(HeadContentSanitizer::cssValue('Inter;}</style><script>alert(1)</script>', 'fallback'))
        ->not->toContain(';')
        ->not->toContain('<')
        ->not->toContain('>');
});

it('keeps safe meta and link snippets while removing executable head html', function (): void {
    $html = '<meta name="x" content="ok"><link rel="canonical" href="https://example.com"><script>alert(1)</script><meta onclick="alert(1)" name="bad" content="x"><link href="javascript:alert(1)" rel="x">';

    $sanitized = HeadContentSanitizer::headSnippet($html);

    expect($sanitized)
        ->toContain('<meta name="x" content="ok">')
        ->toContain('<link rel="canonical" href="https://example.com">')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

it('removes slash-separated event handlers and encoded unsafe URLs', function (): void {
    $sanitized = HeadContentSanitizer::headSnippet(
        '<meta/name="x"/onclick="alert(1)" content="ok"><link rel="canonical" href="&#106;avascript:alert(1)">',
    );

    expect($sanitized)
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('href=');
});
