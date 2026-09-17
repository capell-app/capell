<?php

declare(strict_types=1);

use Capell\Core\Data\Media\MediaCompositionGuidanceData;
use Capell\Core\Data\Media\MediaCompositionQuietRegionData;
use Capell\Core\Enums\Media\MediaCompositionTemplateStatus;
use Capell\Core\Support\Media\MediaCompositionGuidanceRegistry;
use Capell\Core\Support\Media\MediaCropPresetRepository;
use Illuminate\Support\Facades\Storage;

function compositionPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function compositionGuidance(string $path = 'guides/hero.png', string $preset = 'hero', array $quietRegions = []): MediaCompositionGuidanceData
{
    return new MediaCompositionGuidanceData(
        key: 'hero',
        label: 'Hero',
        preset: $preset,
        templatePath: $path,
        templateVersion: '2',
        instructions: 'Keep the subject right of centre.',
        templateDisk: 'guides',
        quietRegions: $quietRegions,
    );
}

beforeEach(function (): void {
    Storage::fake('guides');
    config()->set('capell.media.crop_presets', [
        'hero' => ['label' => 'Hero', 'ratio' => '2:1', 'width' => 40, 'height' => 20],
    ]);
});

it('is bound as a singleton', function (): void {
    expect(resolve(MediaCompositionGuidanceRegistry::class))->toBe(resolve(MediaCompositionGuidanceRegistry::class));
});

it('registers and finds guidance', function (): void {
    $registry = new MediaCompositionGuidanceRegistry(new MediaCropPresetRepository);
    $registry->register(compositionGuidance());

    expect($registry->has('hero'))->toBeTrue()
        ->and($registry->find('hero')?->preset)->toBe('hero')
        ->and($registry->find('other'))->toBeNull();
});

it('rejects incomplete guidance and unsafe template paths', function (string $path): void {
    new MediaCompositionGuidanceRegistry(new MediaCropPresetRepository)->register(compositionGuidance(path: $path));
})->with([
    'empty path' => [''],
    'traversal' => ['../secret.png'],
    'absolute' => ['/etc/hero.png'],
])->throws(InvalidArgumentException::class);

it('reports template status against the crop preset', function (string $scenario, string $expected): void {
    $disk = Storage::disk('guides');
    $files = [
        'available raster' => ['guides/hero.png', compositionPng(40, 20)],
        'available document' => ['guides/hero.pdf', '%PDF-1.4'],
        'dimension mismatch' => ['guides/hero.png', compositionPng(30, 20)],
        'unsupported extension' => ['guides/hero.psd', 'x'],
        'corrupt raster' => ['guides/hero.png', 'not an image'],
        'quiet region outside preset' => ['guides/hero.png', compositionPng(40, 20)],
    ];

    if (isset($files[$scenario])) {
        $disk->put($files[$scenario][0], $files[$scenario][1]);
    }

    $guidance = compositionGuidance(
        path: $files[$scenario][0] ?? 'guides/hero.png',
        preset: $scenario === 'unknown preset' ? 'nope' : 'hero',
        quietRegions: $scenario === 'quiet region outside preset' ? [new MediaCompositionQuietRegionData('Headline', 30, 0, 20, 10)] : [],
    );

    expect(new MediaCompositionGuidanceRegistry(new MediaCropPresetRepository)->status($guidance)->value)->toBe($expected);
})->with([
    'available raster' => ['available raster', MediaCompositionTemplateStatus::Available->value],
    'available document' => ['available document', MediaCompositionTemplateStatus::Available->value],
    'missing' => ['missing', MediaCompositionTemplateStatus::Missing->value],
    'dimension mismatch' => ['dimension mismatch', MediaCompositionTemplateStatus::DimensionMismatch->value],
    'unsupported extension' => ['unsupported extension', MediaCompositionTemplateStatus::UnsupportedFormat->value],
    'corrupt raster' => ['corrupt raster', MediaCompositionTemplateStatus::UnsupportedFormat->value],
    'unknown preset' => ['unknown preset', MediaCompositionTemplateStatus::UnknownPreset->value],
    'quiet region outside preset' => ['quiet region outside preset', MediaCompositionTemplateStatus::InvalidQuietRegion->value],
]);

it('versions the template url', function (): void {
    $url = new MediaCompositionGuidanceRegistry(new MediaCropPresetRepository)->templateUrl(compositionGuidance());

    expect($url)->toContain('guides/hero.png')->toEndWith('v=2');
});
