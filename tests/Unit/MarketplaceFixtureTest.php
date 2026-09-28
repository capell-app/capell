<?php

declare(strict_types=1);

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Support\MarketplaceFixture;

function marketplaceGalleryDirectory(): string
{
    return dirname(__DIR__, 2) . '/workbench/database/screenshot-gallery';
}

function marketplaceGalleryManifest(): string
{
    return marketplaceGalleryDirectory() . '/images.json';
}

beforeEach(function (): void {
    $manifest = marketplaceGalleryManifest();

    expect($manifest)->not->toBeFile(
        'These tests must not overwrite a genuine prepared Marketplace gallery manifest.',
    );

    if (! is_dir(marketplaceGalleryDirectory())) {
        mkdir(marketplaceGalleryDirectory(), 0755, true);
    }
});

afterEach(function (): void {
    $manifest = marketplaceGalleryManifest();

    if (is_file($manifest)) {
        unlink($manifest);
    }
});

it('omits gallery images from the extension response when genuine evidence is absent', function (): void {
    $response = MarketplaceFixture::extensionResponse('https://marketplace.example.test');

    expect($response['data'])
        ->not->toHaveKeys(['image_url', 'images']);
});

it('includes genuine gallery images in the extension response when the manifest is present', function (): void {
    file_put_contents(marketplaceGalleryManifest(), json_encode([
        [
            'filename' => '1.png',
            'caption' => 'SEO audit overview',
            'sha256' => str_repeat('a', 64),
        ],
        [
            'filename' => '2.png',
            'caption' => 'Metadata editor',
            'sha256' => str_repeat('b', 64),
        ],
    ], JSON_THROW_ON_ERROR));

    $response = MarketplaceFixture::extensionResponse('https://marketplace.example.test/');

    expect($response['data']['image_url'])
        ->toBe('https://marketplace.example.test/api/v1/marketplace-fixtures/seo-suite/1.png')
        ->and($response['data']['images'])->toBe([
            [
                'url' => 'https://marketplace.example.test/api/v1/marketplace-fixtures/seo-suite/1.png',
                'alt' => 'SEO audit overview',
                'caption' => 'SEO audit overview',
            ],
            [
                'url' => 'https://marketplace.example.test/api/v1/marketplace-fixtures/seo-suite/2.png',
                'alt' => 'Metadata editor',
                'caption' => 'Metadata editor',
            ],
        ]);
});

it('refuses the gallery image route when genuine evidence is absent', function (): void {
    MarketplaceFixture::imagePath('1.png');
})->throws(NotFoundHttpException::class);
