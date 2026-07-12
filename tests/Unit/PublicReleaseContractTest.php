<?php

declare(strict_types=1);

it('defines the public v4 split package release contract', function (): void {
    $root = dirname(__DIR__, 2);
    $splitPackages = json_decode(
        file_get_contents($root . '/config/release-packages.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($splitPackages)->toBe(['admin', 'core', 'frontend', 'installer', 'marketplace']);

    foreach ($splitPackages as $splitPackage) {
        $manifest = json_decode(
            file_get_contents($root . '/packages/' . $splitPackage . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($manifest['name'])->toBe('capell-app/' . $splitPackage)
            ->and($manifest['extra']['branch-alias']['dev-main'] ?? null)->toBe('4.x-dev');
    }

    $marketplaceManifest = json_decode(
        file_get_contents($root . '/packages/marketplace/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $coreManifest = json_decode(
        file_get_contents($root . '/packages/core/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($coreManifest['require']['spatie/laravel-settings'])->toBe('^3.0')
        ->and($marketplaceManifest['require']['capell-app/admin'])->toBe('self.version')
        ->and($marketplaceManifest['require']['capell-app/core'])->toBe('self.version');

    $splitWorkflow = file_get_contents($root . '/.github/workflows/split-monorepo.yml');
    $releaseSmokeWorkflow = file_get_contents($root . '/.github/workflows/public-release-smoke.yml');
    $fastTestWorkflow = file_get_contents($root . '/.github/workflows/test-fast-pr.yml');
    $fullTestWorkflow = file_get_contents($root . '/.github/workflows/test-full.yml');
    $localSplitScript = file_get_contents($root . '/scripts/local-split-packages.sh');
    $packagistScript = file_get_contents($root . '/scripts/create-packagist-packages.sh');

    expect($splitWorkflow)
        ->toContain('actions/create-github-app-token@fee1f7d63c2ff003460e3d139729b119787bc349')
        ->toContain('SPLIT_APP_ID')
        ->toContain('SPLIT_APP_PRIVATE_KEY')
        ->toContain('Compensate release tags after a split failure')
        ->toContain("if: failure() && steps.split-start.outputs.started == 'true'")
        ->toContain('git/refs/tags/${encoded_tag}')
        ->toContain('pre-split-main-shas.json')
        ->toContain('git/refs/heads/main')
        ->toContain('-F force=true')
        ->toContain('uses: ./.github/workflows/public-release-smoke.yml')
        ->not->toContain('matrix:')
        ->not->toContain('fail-fast: false')
        ->not->toContain('secrets.ACCESS_TOKEN')
        ->and($localSplitScript)->toContain('BRANCH="${CAPELL_SPLIT_BRANCH:-main}"')
        ->toContain('rollback_release_tags')
        ->toContain('git push "${remote_url}" ":refs/tags/${TAG}"')
        ->and($packagistScript)->toContain('PACKAGES+=("capell")')
        ->and($packagistScript)->toContain('--preflight')
        ->and($packagistScript)->toContain('repos/${repository}/contents/composer.json')
        ->and($packagistScript)->toContain('https://packagist.org/api/github')
        ->and($packagistScript)->toContain('Packagist preflight failed.');

    expect($releaseSmokeWorkflow)
        ->toContain('package: [admin, core, frontend, installer, marketplace]')
        ->toContain('Wait for one exact version across every package')
        ->toContain('comm -12')
        ->toContain('composer create-project')
        ->toContain('php artisan capell:install')
        ->toContain('--all-packages')
        ->toContain('--install-welcome-route')
        ->toContain('curl --fail --silent --show-error http://127.0.0.1:8000/')
        ->toContain('php artisan package:discover')
        ->toContain('php artisan migrate --force')
        ->not->toContain("inputs.version || '*'")
        ->and($fastTestWorkflow)
        ->toContain('- main')
        ->toContain('"pestphp/pest:^4.3"')
        ->toContain('"filament/filament:^5.6.8 <5.7.0-beta"')
        ->not->toContain('filament/filament:^4.7')
        ->and($fullTestWorkflow)
        ->toContain('- main');

    preg_match_all('/repository_name:\s*([a-z0-9-]+)/', $splitWorkflow, $splitRepositoryMatches);
    preg_match('/package:\s*\[([^]]+)]/', $releaseSmokeWorkflow, $smokeMatrixMatch);
    $smokePackages = array_map(trim(...), explode(',', $smokeMatrixMatch[1] ?? ''));

    expect($splitRepositoryMatches[1])->toBe($splitPackages)
        ->and($smokePackages)->toBe($splitPackages)
        ->and($localSplitScript)->toContain('config/release-packages.json')
        ->and($packagistScript)->toContain('config/release-packages.json');
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
        ->toContain('current stable 0.0.x foundation release')
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

it('keeps split package readmes standalone', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (['admin', 'core', 'frontend', 'installer', 'marketplace'] as $package) {
        $readme = file_get_contents($root . '/packages/' . $package . '/README.md');

        expect($readme)
            ->not->toContain('../../docs/')
            ->not->toContain('packages/' . $package . '/')
            ->not->toContain('vendor/bin/pest packages/' . $package . '/')
            ->toContain('https://docs.capell.app');
    }
});

it('rejects placeholder changelog entries and generates useful release notes', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = file_get_contents($root . '/.github/workflows/update-changelog.yml');
    $changelog = file_get_contents($root . '/CHANGELOG.md');

    expect($workflow)
        ->toContain('placeholderReleaseNotesPattern')
        ->toContain('generateReleaseNotes')
        ->toContain("core.setFailed('Release notes are empty after generation.')")
        ->and($changelog)
        ->not->toMatch('/Release v2\\.0\\.(?:81|82|83|84|85) for Capell 4\\.x\\./');
});

it('publishes honest comparison recovery and exit guidance without recycled doc heroes', function (): void {
    $root = dirname(__DIR__, 2);
    $docsIndex = file_get_contents($root . '/docs/README.md');
    $comparison = file_get_contents($root . '/docs/getting-started/comparing-capell.md');
    $backups = file_get_contents($root . '/docs/operations/backups.md');
    $exitPlan = file_get_contents($root . '/docs/operations/export-and-exit.md');

    expect($docsIndex)
        ->toContain('getting-started/comparing-capell.md')
        ->toContain('operations/export-and-exit.md')
        ->and($comparison)
        ->toContain('WordPress')
        ->toContain('Craft CMS')
        ->toContain('fit check, not a claim that one tool wins every project')
        ->and($backups)
        ->toContain('Worked production recovery example')
        ->toContain('capell_restore_incident_20260712')
        ->and($exitPlan)
        ->toContain('migration-assistant:export')
        ->toContain('Do not delete the source environment');

    $recycledHeroPattern = '/\\A#[^\\n]+\\n\\n!\\[[^]]+\\]\\(\\.\\.\\/images\\/generated\\/admin\\/(?:site-health-page|theme-library-admin-flow)\\.png\\)$/m';
    $docs = glob($root . '/docs/{frontend,packages,performance,operations,development,platform}/*.md', GLOB_BRACE);

    expect($docs)->not->toBeFalse();

    if ($docs === false) {
        return;
    }

    foreach ($docs as $doc) {
        expect((string) file_get_contents($doc))->not->toMatch($recycledHeroPattern);
    }
});
