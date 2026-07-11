<?php

declare(strict_types=1);

use Capell\Admin\Support\Themes\ThemeFontUploadPolicy;

it('allows only browser-verifiable font MIME types', function (): void {
    expect(ThemeFontUploadPolicy::acceptedFileTypes())
        ->toContain('font/woff2')
        ->toContain('font/woff')
        ->toContain('font/ttf')
        ->toContain('font/otf')
        ->not->toContain('image/svg+xml')
        ->not->toContain('application/vnd.ms-fontobject')
        ->not->toContain('application/octet-stream');
});

it('validates font uploads by extension', function (): void {
    expect(ThemeFontUploadPolicy::validationRules())
        ->toBe(['extensions:woff2,woff,ttf,otf']);
});

it('sanitizes stored font filenames', function (): void {
    expect(ThemeFontUploadPolicy::sanitizeFileName(
        originalName: 'My Brand Font (Bold)!.woff2',
        suffix: '01TEST',
    ))->toBe('my-brand-font-bold-01TEST.woff2');
});
