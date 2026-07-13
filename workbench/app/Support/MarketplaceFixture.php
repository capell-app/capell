<?php

declare(strict_types=1);

namespace Workbench\App\Support;

final class MarketplaceFixture
{
    /**
     * @return array{data: array<string, mixed>}
     */
    public static function extensionResponse(string $webUrl): array
    {
        $baseUrl = rtrim($webUrl, '/');

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
                'image_url' => self::imageUrl($baseUrl, 'logo'),
                'images' => [
                    [
                        'url' => self::imageUrl($baseUrl, 'admin-overview'),
                        'alt' => 'SEO Suite admin overview',
                        'caption' => 'Admin overview',
                    ],
                    [
                        'url' => self::imageUrl($baseUrl, 'frontend-output'),
                        'alt' => 'SEO Suite frontend output',
                        'caption' => 'Frontend output',
                    ],
                    [
                        'url' => self::imageUrl($baseUrl, 'settings'),
                        'alt' => 'SEO Suite settings',
                        'caption' => 'Settings',
                    ],
                    [
                        'url' => self::imageUrl($baseUrl, 'checks'),
                        'alt' => 'SEO Suite checks',
                        'caption' => 'Checks',
                    ],
                    [
                        'url' => self::imageUrl($baseUrl, 'reporting'),
                        'alt' => 'SEO Suite reporting',
                        'caption' => 'Reporting',
                    ],
                ],
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

    public static function imageSvg(string $image): string
    {
        $titles = [
            'admin-overview' => 'Admin overview',
            'frontend-output' => 'Frontend output',
            'settings' => 'Settings',
            'checks' => 'Checks',
            'reporting' => 'Reporting',
            'logo' => 'SEO Suite',
        ];

        $title = $titles[$image] ?? 'SEO Suite';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="760" viewBox="0 0 1200 760" role="img" aria-label="{$title}">
  <rect width="1200" height="760" fill="#0f172a"/>
  <rect x="48" y="48" width="1104" height="664" rx="32" fill="#f8fafc"/>
  <rect x="88" y="104" width="240" height="24" rx="12" fill="#f59e0b"/>
  <rect x="88" y="160" width="512" height="32" rx="16" fill="#1e293b"/>
  <rect x="88" y="232" width="360" height="320" rx="24" fill="#e2e8f0"/>
  <rect x="488" y="232" width="584" height="56" rx="18" fill="#cbd5e1"/>
  <rect x="488" y="320" width="472" height="56" rx="18" fill="#cbd5e1"/>
  <rect x="488" y="408" width="520" height="56" rx="18" fill="#cbd5e1"/>
  <text x="88" y="638" fill="#0f172a" font-family="Inter, Arial, sans-serif" font-size="52" font-weight="700">{$title}</text>
</svg>
SVG;
    }

    private static function imageUrl(string $baseUrl, string $image): string
    {
        return $baseUrl . '/api/v1/marketplace-fixtures/seo-suite/' . $image . '.svg';
    }
}
