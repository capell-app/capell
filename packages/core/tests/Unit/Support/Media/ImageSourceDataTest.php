<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolveImageSourceDataAction;
use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Media\ImageSourcePolicyResolver;
use Capell\Core\Support\Media\ImageSourcePresets;
use Capell\Core\Support\Media\ImageUrlPolicy;

it('allows strict configured https image domains and relative public paths', function (): void {
    $policy = new ImageUrlPolicy;

    expect($policy->allows('https://images.unsplash.com/photo.jpg', ['images.unsplash.com'], true))->toBeTrue()
        ->and($policy->allows('https://images.unsplash.com/photo.jpg?auto=format&w=1200', ['images.unsplash.com'], true))->toBeTrue()
        ->and($policy->allows('/storage/demo/photo.jpg', ['images.unsplash.com'], true))->toBeTrue();
});

it('rejects unsafe or unapproved image URLs', function (string $url): void {
    expect((new ImageUrlPolicy)->allows($url, ['images.unsplash.com'], true))->toBeFalse();
})->with([
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:image/svg+xml;base64,AAAA'],
    'plain http' => ['http://images.unsplash.com/photo.jpg'],
    'protocol relative' => ['//images.unsplash.com/photo.jpg'],
    'unapproved host' => ['https://example.com/photo.jpg'],
]);

it('uses a fresh image URL policy after an Octane request scope is flushed', function (): void {
    $firstSettings = Mockery::mock(CoreSettings::class);
    $firstSettings->allowed_remote_image_domains = ['first.example.com'];
    $firstSettings->allow_relative_image_urls = false;

    app()->instance(CoreSettings::class, $firstSettings);

    $firstPolicy = resolve(ImageUrlPolicy::class);

    expect(resolve(ImageUrlPolicy::class))->toBe($firstPolicy)
        ->and($firstPolicy->allows('https://first.example.com/image.jpg'))->toBeTrue()
        ->and($firstPolicy->allows('/image.jpg'))->toBeFalse();

    $secondSettings = Mockery::mock(CoreSettings::class);
    $secondSettings->allowed_remote_image_domains = ['second.example.com'];
    $secondSettings->allow_relative_image_urls = true;

    app()->forgetScopedInstances();
    app()->instance(CoreSettings::class, $secondSettings);

    $secondPolicy = resolve(ImageUrlPolicy::class);

    expect($secondPolicy)->not->toBe($firstPolicy)
        ->and($secondPolicy->allows('https://first.example.com/image.jpg'))->toBeFalse()
        ->and($secondPolicy->allows('https://second.example.com/image.jpg'))->toBeTrue()
        ->and($secondPolicy->allows('/image.jpg'))->toBeTrue();
});

it('normalizes legacy string URLs into image source data', function (): void {
    $source = ResolveImageSourceDataAction::run('https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1200&q=80');

    expect($source)->not->toBeNull()
        ->and($source->type)->toBe(ImageSourceType::Url)
        ->and($source->url)->toContain('images.unsplash.com');
});

it('normalizes upload paths into public upload image source data', function (): void {
    $source = ResolveImageSourceDataAction::run([
        'type' => 'upload',
        'path' => 'capell/image-sources/example.jpg',
    ]);

    expect($source)->not->toBeNull()
        ->and($source->type)->toBe(ImageSourceType::Upload)
        ->and($source->url)->toContain('capell/image-sources/example.jpg');
});

it('normalizes current media backend sources without changing the relation', function (): void {
    $media = new class implements MediaContract
    {
        public function getUrl(string $conversion = ''): string
        {
            return '/storage/media/example.jpg';
        }

        public function getFullUrl(string $conversion = ''): string
        {
            return 'https://example.test/storage/media/example.jpg';
        }

        public function getAvailableFullUrl(array $conversions): string
        {
            return $this->getFullUrl();
        }

        public function getSrcset(): string
        {
            return '';
        }

        public function hasResponsiveImages(): bool
        {
            return false;
        }

        public function hasConversion(string $conversion): bool
        {
            return false;
        }

        public function getName(): string
        {
            return 'Example';
        }

        public function getPath(): string
        {
            return 'media/example.jpg';
        }

        public function getMimeType(): string
        {
            return 'image/jpeg';
        }

        public function getWidth(): int
        {
            return 1200;
        }

        public function getHeight(): int
        {
            return 800;
        }

        public function getCustomProperty(string $key, mixed $default = null): mixed
        {
            return $default;
        }
    };

    $source = ResolveImageSourceDataAction::run(['type' => 'media'], $media);

    expect($source)->not->toBeNull()
        ->and($source->type)->toBe(ImageSourceType::Media)
        ->and($source->media)->toBe($media)
        ->and($source->width)->toBe(1200)
        ->and(ResolveImageSourceDataAction::run(['type' => 'curator_media']))->toBeNull();
});

it('resolves image source policy precedence from schema then blueprint then global', function (): void {
    $resolver = new ImageSourcePolicyResolver;

    expect($resolver->allowedSources('url_only', 'media_only', 'upload_only'))
        ->toBe([ImageSourceType::Url])
        ->and($resolver->allowedSources(null, 'media_only', 'upload_only'))
        ->toBe([ImageSourceType::Media])
        ->and($resolver->allowedSources(null, null, 'upload_only'))
        ->toBe([ImageSourceType::Upload]);
});

it('exposes preset options for image source policy fields', function (): void {
    expect(ImageSourcePresets::presetOptions())
        ->toHaveKey('all')
        ->toHaveKey('url_media')
        ->toHaveKey('upload_media');
});
