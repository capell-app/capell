<?php

declare(strict_types=1);

use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Models\Media;
use Capell\Frontend\Support\Renderables\RenderableDynamicDataRegistry;
use Capell\Frontend\Support\Renderables\RenderableMediaViewData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

it('filters loaded media by collection and resolves the first external video', function (): void {
    $asset = renderableTestModel();
    $video = new Media(['collection_name' => 'hero']);
    $video->setExternalVideo(new ExternalVideoData(
        provider: 'youtube',
        videoId: 'hero-video',
        url: 'https://youtube.com/watch?v=hero-video',
        embedUrl: 'https://youtube.com/embed/hero-video',
        thumbnailUrl: 'https://img.youtube.com/hero-video.jpg',
    ));
    $video->collection_name = 'hero';

    $image = new Media(['collection_name' => 'hero']);
    $otherCollection = new Media(['collection_name' => 'gallery']);

    $asset->setRelation('media', collect([$video, $image, $otherCollection, renderableTestModel()]));
    $viewData = new RenderableMediaViewData;

    expect($viewData->mediaFor($asset))->toHaveCount(3)
        ->and($viewData->mediaFor($asset, 'hero'))->toEqual([$video, $image])
        ->and($viewData->firstExternalVideo($asset, 'hero'))->toEqual($video->externalVideo())
        ->and($viewData->firstExternalVideo($asset, 'gallery'))->toBeNull();
});

it('returns no media when the relation is not loaded', function (): void {
    expect((new RenderableMediaViewData)->mediaFor(renderableTestModel()))->toBe([]);
});

it('normalises hero media with explicit values and source fallbacks', function (): void {
    $viewData = new RenderableMediaViewData;

    expect($viewData->heroMedia([
        'hero_media' => [
            'mode' => 'custom',
            'sources' => [
                'desktop' => [
                    'image' => '/source.jpg',
                    'video' => '/source.mp4',
                ],
            ],
        ],
        'background_image' => '/explicit.jpg',
        'background_video' => [
            'src' => '/explicit.mp4',
            'poster' => '/poster.jpg',
        ],
        'image_alt' => 'Hero media',
    ]))->toBe([
        'sources' => [
            'desktop' => [
                'image' => '/source.jpg',
                'video' => '/source.mp4',
            ],
        ],
        'mode' => 'custom',
        'backgroundImage' => '/explicit.jpg',
        'backgroundVideoUrl' => '/explicit.mp4',
        'backgroundVideoPoster' => '/poster.jpg',
        'imageAlt' => 'Hero media',
    ]);

    expect($viewData->heroMedia([
        'hero_media' => [
            'sources' => [
                'desktop' => [
                    'image' => '/fallback.jpg',
                    'video' => '/fallback.mp4',
                ],
            ],
        ],
    ]))->toBe([
        'sources' => [
            'desktop' => [
                'image' => '/fallback.jpg',
                'video' => '/fallback.mp4',
            ],
        ],
        'mode' => 'custom',
        'backgroundImage' => '/fallback.jpg',
        'backgroundVideoUrl' => '/fallback.mp4',
        'backgroundVideoPoster' => '/fallback.jpg',
        'imageAlt' => '',
    ]);
});

it('turns off video output when the hero mode is off', function (): void {
    expect((new RenderableMediaViewData)->heroMedia([
        'hero_media' => ['mode' => 'off'],
        'background_video' => ['src' => '/ignored.mp4'],
    ]))->toMatchArray([
        'mode' => 'off',
        'backgroundImage' => null,
        'backgroundVideoUrl' => '',
        'backgroundVideoPoster' => null,
        'imageAlt' => '',
    ]);
});

it('merges wildcard and type-specific dynamic data in registration order', function (): void {
    $registry = new RenderableDynamicDataRegistry;
    $asset = renderableTestModel();
    $translation = renderableTestModel();
    $calls = [];

    $registry->register(RenderableTypeEnum::Page, '*', function (Model $receivedAsset, Model $receivedTranslation, array $meta, string $key) use (&$calls): array {
        $calls[] = [$receivedAsset, $receivedTranslation, $meta, $key];

        return ['nested' => ['wildcard' => true], 'value' => 'wildcard'];
    });
    $registry->register('page', 'hero', function (Model $receivedAsset, Model $receivedTranslation, array $meta, string $key) use (&$calls): array {
        $calls[] = [$receivedAsset, $receivedTranslation, $meta, $key];

        return ['nested' => ['specific' => true], 'value' => 'specific'];
    });

    $data = $registry->data(RenderableTypeEnum::Page, 'hero', $asset, $translation, ['source' => 'test']);

    expect($data)->toBe([
        'nested' => ['wildcard' => true, 'specific' => true],
        'value' => 'specific',
    ])
        ->and($calls)->toHaveCount(2)
        ->and($calls[0][0])->toBe($asset)
        ->and($calls[1][1])->toBe($translation)
        ->and($calls[1][3])->toBe('hero')
        ->and((new RenderableDynamicDataRegistry)->data('page', 'missing', $asset, $translation, []))->toBe([]);
});

function renderableTestModel(): Model
{
    return new class extends Model
    {
        use HasFactory;
    };
}
