<?php

declare(strict_types=1);

use Capell\Core\Support\Security\PublicHtmlSanitizer;

it('sanitizes public html with the shared allow list', function (): void {
    $html = '<p onclick="alert(1)"><a href="javascript:alert(2)">Book</a></p><script>alert(3)</script><span class="eyebrow">Safe</span>';

    expect((new PublicHtmlSanitizer)->sanitize($html))
        ->toBe('<p><a>Book</a></p><span>Safe</span>');
});

it('discards dangerous tags nested inside an unwrapped disallowed element', function (): void {
    expect((new PublicHtmlSanitizer)->sanitize('<marquee><iframe src="//evil.example"></iframe></marquee>'))
        ->toBe('');
});

it('strips event handlers and javascript urls hoisted out of an unwrapped disallowed element', function (): void {
    expect((new PublicHtmlSanitizer)->sanitize('<div><b><img src="javascript:1" onerror="steal()"></b></div>'))
        ->not->toContain('javascript:')
        ->not->toContain('onerror');
});

it('sanitizes anchors hoisted out of an unwrapped disallowed element', function (): void {
    expect((new PublicHtmlSanitizer)->sanitize('<b><a href="javascript:alert(1)">Book</a></b>'))
        ->toBe('<a>Book</a>');
});

it('discards scripts nested inside an unwrapped unknown element', function (): void {
    expect((new PublicHtmlSanitizer)->sanitize('<custom><script>alert(1)</script></custom>'))
        ->toBe('');
});

it('sanitizes nested public payload values and removes blocked authoring keys', function (): void {
    $payload = [
        'copy' => '<div onclick="alert(1)">Public</div>',
        'admin_url' => '/admin/pages/1?signature=secret',
        'nested' => [
            'summary' => '<strong data-capell-authoring="true">Safe</strong>',
            'token' => 'secret-token',
        ],
    ];

    expect((new PublicHtmlSanitizer)->sanitizePublicValue($payload))
        ->toBe([
            'copy' => '<div>Public</div>',
            'nested' => [
                'summary' => '<strong>Safe</strong>',
            ],
        ]);
});

it('removes reserved editor envelopes while preserving public content and ordinary keys', function (): void {
    $payload = [
        'capell' => ['caption' => 'Public brand'],
        'state_version' => 3,
        'content' => [[
            'type' => 'chart',
            'data' => [
                'title' => 'Workshop attendance',
                '__capell' => ['instance_id' => 'private-editor-instance', 'state_version' => 1, 'presentation' => ['editor' => true]],
            ],
        ]],
        'nested' => ['__CAPELL' => ['instance_id' => 'another-editor-instance']],
        'admin-url' => '/admin/pages/1',
    ];

    expect((new PublicHtmlSanitizer)->sanitizePublicValue($payload))->toBe([
        'capell' => ['caption' => 'Public brand'],
        'state_version' => 3,
        'content' => [['type' => 'chart', 'data' => ['title' => 'Workshop attendance']]],
        'nested' => [],
    ]);
});

it('keeps the content that follows an unbalanced closing tag', function (string $html, string $expected): void {
    expect((new PublicHtmlSanitizer)->sanitize($html))->toBe($expected);
})->with([
    'stray closing div' => ['<p>a</p></div><p>b tail kept</p>', '<p>a</p><p>b tail kept</p>'],
    'stray closing span and p' => ['<p>one</span></p><p>two</p>', '<p>one</p><p>two</p>'],
    'closing div before text' => ['</div>plain text after', 'plain text after'],
]);

it('still removes script after an unbalanced closing tag', function (): void {
    expect((new PublicHtmlSanitizer)->sanitize('<p>a</p></div><script>alert(1)</script><p onclick="x()">b</p>'))
        ->toBe('<p>a</p><p>b</p>');
});

it('cannot be steered by document-level tags in the input', function (string $html): void {
    expect((new PublicHtmlSanitizer)->sanitize($html))
        ->not->toContain('onload')
        ->not->toContain('onclick')
        ->not->toContain('<script')
        ->not->toContain('<body')
        ->not->toContain('<html')
        ->not->toContain('<head')
        ->toContain('kept');
})->with([
    'second body with handler' => ['<p>kept</p></body><body onload="x()"><p>more</p>'],
    'html element with handler' => ['<html onclick="x()"><p>kept</p></html>'],
    'head content hoisting' => ['<head><script>alert(1)</script></head><p>kept</p>'],
    'leading script and meta' => ['<meta http-equiv="refresh" content="0;url=//evil.test"><script>x()</script><p>kept</p>'],
]);
