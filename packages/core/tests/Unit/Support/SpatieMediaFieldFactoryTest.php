<?php

declare(strict_types=1);

use Capell\Core\Support\Media\SpatieMediaFieldFactory;

it('enables the fallback Spatie image editor with configured crop ratios', function (): void {
    config()->set('capell.media.crop_presets', [
        'thumbnail' => [
            'label' => 'Thumbnail',
            'ratio' => '1:1',
            'width' => 320,
            'height' => 320,
        ],
        'hero' => [
            'label' => 'Hero',
            'ratio' => '16:9',
            'width' => 1600,
            'height' => 900,
        ],
    ]);

    $field = resolve(SpatieMediaFieldFactory::class)->make('image');

    expect($field->hasImageEditor())
        ->toBeTrue()
        ->and($field->getImageEditorMode())
        ->toBe(2)
        ->and($field->getPanelLayout())
        ->toBe('grid')
        ->and(array_keys($field->getImageEditorAspectRatioOptionsForJs()))
        ->toBe([
            __('filament-forms::components.file_upload.editor.aspect_ratios.no_fixed.label'),
            '1:1',
            '16:9',
        ]);
});
