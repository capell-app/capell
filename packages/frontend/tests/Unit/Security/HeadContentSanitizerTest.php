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

it('drops redirecting, cookie-setting and policy-rewriting meta http-equiv tags but keeps harmless ones', function (): void {
    $sanitized = HeadContentSanitizer::headSnippet(
        '<meta http-equiv="refresh" content="0;url=https://evil.example">'
        . '<meta http-equiv=" Set-Cookie " content="a=b">'
        . '<meta http-equiv="Content-Security-Policy" content="default-src *">'
        . '<meta http-equiv="X-UA-Compatible" content="IE=edge">',
    );

    expect($sanitized)
        ->not->toContain('refresh')
        ->not->toContain('evil.example')
        ->not->toContain('Set-Cookie')
        ->not->toContain('Content-Security-Policy')
        ->toContain('<meta http-equiv="X-UA-Compatible" content="IE=edge">');
});

it('drops links that could load stylesheets or other active content', function (): void {
    $sanitized = HeadContentSanitizer::headSnippet(
        '<link rel="stylesheet" href="//evil.example/a.css">'
        . '<link rel="icon stylesheet" href="//evil.example/b.css">'
        . '<link href="//evil.example/c.css">'
        . '<link rel="preload" as="script" href="//evil.example/d.js">'
        . '<link rel="ICON" href="/favicon.ico">'
        . '<link rel="alternate" hreflang="fr" href="https://example.com/fr">',
    );

    expect($sanitized)
        ->not->toContain('evil.example')
        ->toContain('<link rel="ICON" href="/favicon.ico">')
        ->toContain('hreflang="fr"');
});
