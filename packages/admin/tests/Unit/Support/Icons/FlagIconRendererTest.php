<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Support\FlagIconRenderer as FlagIconRendererContract;
use Capell\Admin\Support\Icons\FlagIconRenderer;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

it('renders a flag glyph without the address package', function (): void {
    $html = resolve(FlagIconRendererContract::class)
        ->render('flag-4x3-fr', 'France', attributes: ['class' => 'flag-renderer-test-class'])
        ->toHtml();

    expect($html)
        ->toContain("\u{1F1EB}\u{1F1F7}")
        ->toContain('aria-label="France"')
        ->toContain('flag-renderer-test-class')
        ->not->toContain('<img')
        ->not->toContain('capell-address');
});

it('renders subdivision flags as flag glyphs', function (): void {
    $html = resolve(FlagIconRenderer::class)
        ->render('gb-eng', 'England')
        ->toHtml();

    expect($html)
        ->toContain("\u{1F3F4}")
        ->toContain('aria-label="England"')
        ->not->toContain('<img');
});

it('keeps optional address package components out of admin blades', function (): void {
    $bladeFiles = collect(File::allFiles(__DIR__ . '/../../../../resources/views'))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'));

    foreach ($bladeFiles as $bladeFile) {
        expect($bladeFile->getContents())
            ->not->toContain('capell-address::')
            ->not->toContain('x-capell-address');
    }
});
