<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use RuntimeException;

final class MarketplaceFixture
{
    /**
     * @return array{data: array<string, mixed>}
     */
    public static function extensionResponse(string $webUrl): array
    {
        $baseUrl = rtrim($webUrl, '/');
        $galleryImages = is_file(self::galleryManifest())
            ? self::galleryImages($baseUrl)
            : [];

        return [
            'data' => [
                'slug' => 'seo-suite',
                'name' => 'Advanced SEO Suite',
                'display_name' => 'Advanced SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'plugin',
                'description' => 'SEO tools for Capell.',
                'documentation_url' => $baseUrl . '/docs/marketplace/seo-suite',
                'purchase_url' => $baseUrl . '/extensions/seo-suite',
                'price_cents' => 4900,
                'is_paid' => true,
                ...($galleryImages === [] ? [] : [
                    'image_url' => $galleryImages[0]['url'],
                    'images' => $galleryImages,
                ]),
                'product' => [
                    'group' => 'Marketing',
                    'tier' => 'premium',
                    'bundle' => 'growth',
                ],
                'commercial' => [
                    'requestedCertification' => 'first-party',
                    'supportPolicy' => 'priority',
                ],
                'surfaces' => ['admin', 'frontend'],
                'dependencies' => [
                    'requires' => ['capell-app/html-cache'],
                ],
                'performance' => [
                    'frontendRenderBudgetMs' => 15,
                ],
                'contribution_summary' => [
                    'admin-page' => 1,
                    'frontend-component' => 2,
                ],
                'documentation' => [
                    [
                        'title' => 'Setup guide',
                        'url' => $baseUrl . '/docs/marketplace/seo-suite/setup',
                        'private' => false,
                    ],
                    [
                        'title' => 'Private optimization playbook',
                        'url' => $baseUrl . '/docs/marketplace/seo-suite/private-playbook',
                        'private' => true,
                    ],
                ],
                'version_history' => [
                    ['version' => '2.1.0', 'released_at' => '2026-05-01'],
                    ['version' => '2.0.0', 'released_at' => '2026-04-10'],
                ],
                'install_eligibility' => 'allowed',
                'next_action' => 'Install from Marketplace',
                'health_status' => 'ok',
                'private_docs_entitled' => true,
                'licence' => [
                    'licence_status' => 'active',
                    'can_comment' => true,
                    'can_rate' => true,
                    'can_download' => true,
                    'can_install' => true,
                ],
            ],
        ];
    }

    public static function imagePath(string $image): string
    {
        $directory = dirname(__DIR__, 2) . '/database/screenshot-gallery/';
        $manifest = $directory . 'images.json';
        abort_unless(is_file($manifest), 404);
        $images = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        foreach ($images as $entry) {
            if ($entry['filename'] === $image && is_file($directory . $image)) {
                abort_unless(hash_file('sha256', $directory . $image) === $entry['sha256'], 409);

                return $directory . $image;
            }
        }

        abort(404);
    }

    /** @return list<array{url: string, alt: string, caption: string}> */
    private static function galleryImages(string $baseUrl): array
    {
        $manifest = self::galleryManifest();
        throw_unless(is_file($manifest), RuntimeException::class, 'Prepare genuine Marketplace gallery captures before requesting this fixture.');
        $images = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        throw_unless(is_array($images) && count($images) >= 2, RuntimeException::class, 'The Marketplace gallery needs two genuine captures.');

        $galleryImages = [];

        foreach ($images as $image) {
            throw_unless(is_array($image), RuntimeException::class, 'The Marketplace gallery manifest contains an invalid entry.');

            $filename = $image['filename'] ?? null;
            $caption = $image['caption'] ?? null;

            throw_unless(
                is_string($filename) && $filename !== '' && is_string($caption) && $caption !== '',
                RuntimeException::class,
                'The Marketplace gallery manifest contains an invalid entry.',
            );

            $galleryImages[] = [
                'url' => $baseUrl . '/api/v1/marketplace-fixtures/seo-suite/' . $filename,
                'alt' => $caption,
                'caption' => $caption,
            ];
        }

        return $galleryImages;
    }

    private static function galleryManifest(): string
    {
        return dirname(__DIR__, 2) . '/database/screenshot-gallery/images.json';
    }
}
