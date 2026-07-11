<?php

declare(strict_types=1);

use Capell\Core\Models\Media;
use Capell\Core\Models\PageRoleRestriction;
use Capell\Core\Support\Media\CustomPathGenerator;
use Capell\Core\Support\Media\MediaModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

it('resolves the configured media model only when the class exists', function (): void {
    config(['capell.media.model' => null]);

    expect(MediaModel::class())->toBe(Media::class)
        ->and(MediaModel::instance())->toBeInstanceOf(Media::class);

    config(['capell.media.model' => 'Missing\\MediaModel']);

    expect(MediaModel::class())->toBe(Media::class);

    config(['capell.media.model' => Media::class]);

    expect(MediaModel::class())->toBe(Media::class)
        ->and(MediaModel::query()->getModel())->toBeInstanceOf(Media::class);
});

it('builds deterministic media paths from collection, model type, name, and id', function (): void {
    config(['media-library.prefix' => 'uploads']);

    $media = new Media([
        'collection_name' => 'hero_images',
        'model_type' => 'App\\Models\\MarketingPage',
        'name' => 'Launch Hero Image',
    ]);
    $media->id = 42;

    $generator = new class extends CustomPathGenerator
    {
        public function basePathFor(SpatieMedia $media): string
        {
            return $this->getBasePath($media);
        }
    };

    expect($generator->basePathFor($media))
        ->toBe('uploads/hero_images/marketing-page/launch-hero-image-42/');
});

it('exposes page role restriction ownership relations', function (): void {
    $restriction = new PageRoleRestriction;

    expect($restriction->restrictable())->toBeInstanceOf(MorphTo::class)
        ->and($restriction->role())->toBeInstanceOf(BelongsTo::class);
});
