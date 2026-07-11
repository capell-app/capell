<?php

declare(strict_types=1);

it('defines the public v0.0.2 split package release contract', function (): void {
    $root = dirname(__DIR__, 2);
    $splitPackages = ['admin', 'core', 'frontend', 'installer', 'marketplace'];

    foreach ($splitPackages as $splitPackage) {
        $manifest = json_decode(
            file_get_contents($root . '/packages/' . $splitPackage . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($manifest['name'])->toBe('capell-app/' . $splitPackage)
            ->and($manifest['extra']['branch-alias']['dev-main'] ?? null)->toBe('0.0.x-dev');
    }

    $marketplaceManifest = json_decode(
        file_get_contents($root . '/packages/marketplace/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($marketplaceManifest['require']['capell-app/admin'])->toBe('^0.0.2 || 0.0.x-dev')
        ->and($marketplaceManifest['require']['capell-app/core'])->toBe('^0.0.2 || 0.0.x-dev');

    $splitWorkflow = file_get_contents($root . '/.github/workflows/split-monorepo.yml');
    $localSplitScript = file_get_contents($root . '/scripts/local-split-packages.sh');
    $packagistScript = file_get_contents($root . '/scripts/create-packagist-packages.sh');

    expect($splitWorkflow)->toContain("branch: 'main'")
        ->and($localSplitScript)->toContain('BRANCH="${CAPELL_SPLIT_BRANCH:-main}"')
        ->and($packagistScript)->toContain('PACKAGES+=("capell")')
        ->and($packagistScript)->toContain('--preflight')
        ->and($packagistScript)->toContain('repos/${repository}/contents/composer.json')
        ->and($packagistScript)->toContain('https://packagist.org/api/github')
        ->and($packagistScript)->toContain('Packagist preflight failed.');
});
