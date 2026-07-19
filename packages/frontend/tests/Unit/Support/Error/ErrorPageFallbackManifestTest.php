<?php

declare(strict_types=1);

use Capell\Frontend\Support\Error\ErrorPageFallbackManifest;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

afterEach(function (): void {
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

it('reads the current manifest for each fallback operation without crossing host branding', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);

    $store->write([
        'default' => fallbackBranding('Default A'),
        'hosts' => [
            'alpha.test' => fallbackBranding('Alpha A'),
            'beta.test' => fallbackBranding('Beta A'),
        ],
    ]);

    expect(ErrorPageFallbackManifest::forHost('alpha.test', '500'))
        ->toBe([
            'logo_url' => 'https://cdn.test/Alpha A.svg',
            'copy' => ['headline' => 'Alpha A headline', 'description' => 'Alpha A description'],
        ])
        ->and(ErrorPageFallbackManifest::forHost('beta.test', '500')['logo_url'])
        ->toBe('https://cdn.test/Beta A.svg');

    $store->write([
        'default' => fallbackBranding('Default B'),
        'hosts' => [
            'alpha.test' => fallbackBranding('Alpha B'),
            'gamma.test' => fallbackBranding('Gamma B'),
        ],
    ]);

    expect(ErrorPageFallbackManifest::forHost('alpha.test', '500'))
        ->toBe([
            'logo_url' => 'https://cdn.test/Alpha B.svg',
            'copy' => ['headline' => 'Alpha B headline', 'description' => 'Alpha B description'],
        ])
        ->and(ErrorPageFallbackManifest::forHost('beta.test', '500'))
        ->toBe([
            'logo_url' => 'https://cdn.test/Default B.svg',
            'copy' => ['headline' => 'Default B headline', 'description' => 'Default B description'],
        ])
        ->and(ErrorPageFallbackManifest::forHost('gamma.test', '500')['logo_url'])
        ->toBe('https://cdn.test/Gamma B.svg');
});

it('makes store updates immediately visible to the fallback reader', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);

    $store->setDefault('https://cdn.test/default-a.svg', [
        500 => ['headline' => 'Default A', 'description' => 'Default A description'],
    ]);
    $store->setHost('alpha.test', 'https://cdn.test/alpha-a.svg', [
        500 => ['headline' => 'Alpha A', 'description' => 'Alpha A description'],
    ]);

    expect(ErrorPageFallbackManifest::logoUrl('alpha.test'))->toBe('https://cdn.test/alpha-a.svg')
        ->and(ErrorPageFallbackManifest::copy('alpha.test', '500'))->toBe([
            'headline' => 'Alpha A',
            'description' => 'Alpha A description',
        ]);

    $store->setDefault('https://cdn.test/default-b.svg', [
        500 => ['headline' => 'Default B', 'description' => 'Default B description'],
    ]);
    $store->setHost('alpha.test', 'https://cdn.test/alpha-b.svg', [
        500 => ['headline' => 'Alpha B', 'description' => 'Alpha B description'],
    ]);

    expect(ErrorPageFallbackManifest::forHost('alpha.test', '500'))->toBe([
        'logo_url' => 'https://cdn.test/alpha-b.svg',
        'copy' => ['headline' => 'Alpha B', 'description' => 'Alpha B description'],
    ])->and(ErrorPageFallbackManifest::forHost('unknown.test', '500'))->toBe([
        'logo_url' => 'https://cdn.test/default-b.svg',
        'copy' => ['headline' => 'Default B', 'description' => 'Default B description'],
    ]);
});

/** @return array{logo_url: string, copy: array<int, array{headline: string, description: string}>} */
function fallbackBranding(string $name): array
{
    return [
        'logo_url' => 'https://cdn.test/' . $name . '.svg',
        'copy' => [
            500 => [
                'headline' => $name . ' headline',
                'description' => $name . ' description',
            ],
        ],
    ];
}
