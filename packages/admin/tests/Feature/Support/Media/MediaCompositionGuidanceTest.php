<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Data\Media\MediaCompositionGuidanceData;
use Capell\Core\Data\Media\MediaCompositionQuietRegionData;
use Capell\Core\Support\Media\MediaCompositionGuidanceRegistry;
use Dom\HTMLDocument;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

function guidancePng(int $width, int $height): string
{
    throw_if($width < 1 || $height < 1, InvalidArgumentException::class, 'Guidance image dimensions must be positive.');

    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function mountedGuidanceSchema(Field $field): Schema
{
    return Schema::make(Livewire::make()->data(['image' => 'kept']))
        ->statePath('data')
        ->components([$field]);
}

function guidanceDocument(Field $field): HTMLDocument
{
    return HTMLDocument::createFromString('<!DOCTYPE html><body>' . aboveContentHtml($field) . '</body>', LIBXML_NOERROR);
}

function aboveContentHtml(Field $field): string
{
    $schema = mountedGuidanceSchema($field);
    /** @var Field $mounted */
    $mounted = $schema->getComponents()[0];

    return (string) $mounted->getChildSchema(Field::ABOVE_CONTENT_SCHEMA_KEY)?->toHtml();
}

beforeEach(function (): void {
    // Swap the Spatie upload for a plain field so tests exercise guidance, not media persistence.
    app()->bind(AdminMediaFieldFactory::class, static fn (): AdminMediaFieldFactory => new class implements AdminMediaFieldFactory
    {
        public function make(string $name): TextInput
        {
            return TextInput::make($name);
        }
    });

    Storage::fake('guides');
    config()->set('capell.media.crop_presets', [
        'hero' => ['label' => 'Hero', 'ratio' => '2:1', 'width' => 40, 'height' => 20],
    ]);

    resolve(MediaCompositionGuidanceRegistry::class)->register(new MediaCompositionGuidanceData(
        key: 'hero',
        label: 'Hero banner',
        preset: 'hero',
        templatePath: 'guides/hero.png',
        templateVersion: '3',
        instructions: 'Place the subject on the right.',
        templateDisk: 'guides',
        layoutVariants: ['split', 'overlay'],
        quietRegions: [new MediaCompositionQuietRegionData('Headline', 0, 0, 20, 10)],
    ));
});

it('renders accessible guidance with a labelled template download above the upload control', function (): void {
    Storage::disk('guides')->put('guides/hero.png', guidancePng(40, 20));

    $document = guidanceDocument(MediaLibraryFileUpload::makeWithGuidance('image', 'hero'));
    $section = $document->querySelector('[data-media-composition-guidance]');
    $links = $document->querySelectorAll('[data-media-composition-guidance] a[download]');
    $link = $links->item(0);

    expect($section?->getAttribute('data-media-composition-guidance-status'))->toBe('available')
        ->and($section?->getAttribute('aria-label'))->toBe('Composition guide: Hero banner')
        ->and($links->length)->toBe(1)
        ->and($link?->getAttribute('download'))->toBe('hero.png')
        ->and($link?->getAttribute('href'))->toContain('guides/hero.png')->toEndWith('v=3')
        ->and($link?->getAttribute('aria-label'))->toBe('Download the Hero banner template, 40 by 20 pixels, 2:1 aspect ratio (version 3)')
        ->and($section?->textContent)->toContain('Place the subject on the right.')
        ->toContain('40 × 20 px (2:1)')
        ->toContain('Headline: 20 × 10 px starting 0 px from the left and 0 px from the top.')
        ->toContain('Used by layouts: split, overlay.')
        ->and($document->querySelectorAll('[role="alert"]')->length)->toBe(0);
});

it('flags a missing template visibly instead of linking to it', function (): void {
    $document = guidanceDocument(MediaLibraryFileUpload::makeWithGuidance('image', 'hero'));

    expect($document->querySelector('[data-media-composition-guidance]')?->getAttribute('data-media-composition-guidance-status'))->toBe('missing')
        ->and($document->querySelectorAll('a[download], a[href]')->length)->toBe(0)
        ->and(trim((string) $document->querySelector('[role="alert"]')?->textContent))->toBe(__('capell-admin::media.composition_guidance.status.missing'));
});

it('flags a template whose dimensions no longer match the preset', function (): void {
    Storage::disk('guides')->put('guides/hero.png', guidancePng(80, 20));

    $document = guidanceDocument(MediaLibraryFileUpload::makeWithGuidance('image', 'hero'));

    expect($document->querySelectorAll('a[download]')->length)->toBe(0)
        ->and(trim((string) $document->querySelector('[role="alert"]')?->textContent))->toBe(__('capell-admin::media.composition_guidance.status.dimension_mismatch'));
});

it('flags an unregistered guidance key', function (): void {
    $document = guidanceDocument(MediaLibraryFileUpload::makeWithGuidance('image', 'unknown'));

    expect(trim((string) $document->querySelector('[role="alert"]')?->textContent))->toBe(__('capell-admin::media.composition_guidance.status.unregistered'));
});

it('keeps guidance out of dehydrated form state', function (): void {
    Storage::disk('guides')->put('guides/hero.png', guidancePng(40, 20));

    $state = mountedGuidanceSchema(MediaLibraryFileUpload::makeWithGuidance('image', 'hero'))->getState();

    expect($state)->toBe(['image' => 'kept']);
});

it('leaves unguided fields unchanged', function (): void {
    $schema = mountedGuidanceSchema(MediaLibraryFileUpload::make('image'));
    /** @var Field $field */
    $field = $schema->getComponents()[0];

    expect($field->getChildSchema(Field::ABOVE_CONTENT_SCHEMA_KEY))->toBeNull()
        ->and($schema->getState())->toBe(['image' => 'kept']);
});
