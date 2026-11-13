<?php

declare(strict_types=1);

use Capell\Core\Exceptions\UnsafeSvgUploadException;
use Capell\Core\Support\Security\SvgContentSniffer;
use Capell\Core\Support\Security\SvgUploadSanitizer;

it('keeps drawing markup and removes everything that can execute or load', function (): void {
    $svg = <<<'SVG'
        <?xml version="1.0"?>
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="steal()">
          <!-- <script>alert(1)</script> -->
          <defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs>
          <script>alert(1)</script>
          <style>@import url(//evil.example/x.css);</style>
          <foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="//evil.example"></iframe></body></foreignObject>
          <a href="javascript:alert(1)"><text>Click</text></a>
          <animate attributeName="href" to="javascript:alert(1)"/>
          <set attributeName="onclick" to="alert(1)"/>
          <image href="https://evil.example/track.png"/>
          <use xlink:href="https://evil.example/sprite.svg#x"/>
          <use href="#g"/>
          <rect width="10" height="10" fill="url(#g)" style="fill: #000; stroke-width: 2"/>
          <rect fill="url(https://evil.example/x)" style="background: url(//evil.example)"/>
          <circle r="2" filter="url(javascript:alert(1))"/>
        </svg>
        SVG;

    $sanitized = (new SvgUploadSanitizer)->sanitize($svg);

    expect($sanitized)
        ->toContain('<linearGradient id="g">')
        ->toContain('<use href="#g"/>')
        ->toContain('fill="url(#g)" style="fill: #000; stroke-width: 2"')
        ->not->toMatch('/<(script|style|foreignObject|iframe|a|animate|set|image)\b/i')
        ->not->toMatch('/\son[a-z]+=/i')
        ->not->toContain('evil.example')
        ->not->toContain('javascript:')
        ->not->toContain('<!--')
        ->not->toContain('Click');
});

it('rejects uploads it cannot reduce to a safe SVG document', function (string $contents): void {
    expect(fn (): string => (new SvgUploadSanitizer)->sanitize($contents))->toThrow(UnsafeSvgUploadException::class);
})->with([
    'empty' => [''],
    'doctype after a long prologue' => ['<!--' . str_repeat('x', 8192) . '--><!DOCTYPE svg [<!ENTITY a "aaaa">]><svg xmlns="http://www.w3.org/2000/svg">&a;</svg>'],
    'external entity' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'],
    'html root' => ['<html><body><svg xmlns="http://www.w3.org/2000/svg"/></body></html>'],
    'svg without namespace' => ['<svg><path d="M0 0"/></svg>'],
    'malformed' => ['<svg xmlns="http://www.w3.org/2000/svg"><g></svg>'],
    'oversized' => ['<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat(' ', SvgUploadSanitizer::MAX_BYTES) . '</svg>'],
]);

it('finds an svg element past any prologue in strings and streams', function (): void {
    $contents = "\xEF\xBB\xBF" . str_repeat(' ', 70_000) . '<!--' . str_repeat('c', 140_000) . '--><svg xmlns="http://www.w3.org/2000/svg"></svg>';
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $contents);
    fseek($stream, 0);

    expect(SvgContentSniffer::isSvg($contents))->toBeTrue()
        ->and(SvgContentSniffer::streamIsSvg($stream))->toBeTrue()
        ->and(ftell($stream))->toBe(0);

    fclose($stream);
});

it('finds an svg tag split across stream chunks', function (): void {
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, str_repeat('a', 65_534) . '<svg>');
    fseek($stream, 0);

    expect(SvgContentSniffer::streamIsSvg($stream))->toBeTrue();

    fclose($stream);
});

it('treats a declared svg type or extension as svg and plain images as not', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

    expect(SvgContentSniffer::isSvg('plain', 'image/svg+xml'))->toBeTrue()
        ->and(SvgContentSniffer::isSvg('plain', null, '.SVGZ'))->toBeTrue()
        ->and(SvgContentSniffer::isSvg((string) $png, 'image/png', 'png'))->toBeFalse();
});
