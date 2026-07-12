<?php

declare(strict_types=1);

it('defines the public v0.0.4 split package release contract', function (): void {
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

    expect($marketplaceManifest['require']['capell-app/admin'])->toBe('^0.0.4 || 0.0.x-dev')
        ->and($marketplaceManifest['require']['capell-app/core'])->toBe('^0.0.4 || 0.0.x-dev');

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

it('documents one public distribution and commercial licensing story', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = file_get_contents($root . '/README.md');
    $quickstart = file_get_contents($root . '/docs/getting-started/quickstart.md');
    $install = file_get_contents($root . '/docs/getting-started/install.md');
    $supportPolicy = file_get_contents($root . '/packages/core/README.md');

    expect($readme)
        ->toContain('public source repositories')
        ->toContain('public Packagist packages')
        ->toContain('Capell licence')
        ->not->toContain('not published through a public source repository')
        ->not->toContain('distributed through private Composer access')
        ->and($quickstart)
        ->toContain('stable `v0.0.4` foundation release')
        ->toContain('public Packagist')
        ->and($install)
        ->toContain('Install the public foundation')
        ->toContain('Paid marketplace packages use authenticated Composer access')
        ->not->toContain('Configure private Capell access')
        ->not->toContain('Do not substitute public Packagist')
        ->and($supportPolicy)
        ->toContain('current stable 0.0.x release')
        ->toContain('The published 1.x maintenance windows begin with Capell 1.0')
        ->not->toContain('For each Capell 1.x minor release');
});
