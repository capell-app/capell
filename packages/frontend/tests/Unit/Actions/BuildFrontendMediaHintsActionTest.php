<?php

declare(strict_types=1);

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\BuildFrontendMediaHintsAction;
use Capell\Frontend\Data\FrontendRenderContextData;

it('selects the page image as the first conservative lcp media hint when it is already loaded', function (): void {
    $media = new class implements MediaContract
    {
        public function getUrl(string $conversion = ''): string
        {
            return '/storage/hero.jpg';
        }

        public function getFullUrl(string $conversion = ''): string
        {
            return $conversion === ''
                ? 'https://example.test/storage/hero.jpg'
                : sprintf('https://example.test/storage/conversions/hero-%s.webp', $conversion);
        }

        public function getAvailableFullUrl(array $conversions): string
        {
            expect($conversions)->toBe(['large', 'medium', 'small', 'thumbnail']);

            return 'https://example.test/storage/conversions/hero-large.webp';
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
            return in_array($conversion, ['thumbnail', 'small', 'medium', 'large'], true);
        }

        public function getName(): string
        {
            return 'Hero';
        }

        public function getPath(): string
        {
            return 'hero.jpg';
        }

        public function getMimeType(): string
        {
            return 'image/jpeg';
        }

        public function getWidth(): int
        {
            return 1600;
        }

        public function getHeight(): int
        {
            return 900;
        }

        public function getCustomProperty(string $key, mixed $default = null): mixed
        {
            return $default;
        }
    };
    $page = Page::factory()->make();
    $page->setRelation('image', $media);

    $hints = BuildFrontendMediaHintsAction::run(new FrontendRenderContextData(
        page: $page,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->url)->toBe('https://example.test/storage/conversions/hero-large.webp')
        ->and($hints[0]->mediaUrl)->toBe('https://example.test/storage/hero.jpg')
        ->and($hints[0]->imageSrcset)->toBe(implode(', ', [
            'https://example.test/storage/conversions/hero-thumbnail.webp 320w',
            'https://example.test/storage/conversions/hero-small.webp 640w',
            'https://example.test/storage/conversions/hero-medium.webp 1280w',
            'https://example.test/storage/conversions/hero-large.webp 2560w',
        ]))
        ->and($hints[0]->imageSizes)->toBe('100vw')
        ->and($hints[0]->mimeType)->toBe('image/webp')
        ->and($hints[0]->fetchPriority)->toBe('high');
});
