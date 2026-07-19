<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Composer\Semver\Semver;

it('defines the public v1 split package release contract', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var list<array{name: string, path: string}> $splitPackages */
    $splitPackages = json_decode(
        file_get_contents($root . '/config/release-packages.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(array_column($splitPackages, 'name'))->toBe([
        'capell-app/core', 'capell-app/admin', 'capell-app/frontend', 'capell-app/installer', 'capell-app/marketplace',
    ]);

    foreach ($splitPackages as $definition) {
        $splitPackage = basename((string) $definition['path']);
        $manifest = json_decode(
            file_get_contents($root . '/packages/' . $splitPackage . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($manifest['name'])->toBe('capell-app/' . $splitPackage)
            ->and($manifest['extra']['branch-alias']['dev-main'] ?? null)->toBe('1.x-dev');

        $capellManifest = json_decode(
            file_get_contents($root . '/packages/' . $splitPackage . '/capell.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect(Semver::satisfies(
            CapellExtensionApi::CURRENT_VERSION,
            (string) ($capellManifest['capellApiVersion'] ?? ''),
        ))->toBeTrue(sprintf(
            '%s must support Capell extension API %s before its split artifact can be published.',
            $manifest['name'],
            CapellExtensionApi::CURRENT_VERSION,
        ));
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

    foreach (['admin', 'frontend', 'installer'] as $foundationPackage) {
        $manifest = json_decode(
            file_get_contents($root . '/packages/' . $foundationPackage . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($manifest['require']['capell-app/core'])->toBe('self.version');
    }

    $descriptions = collect($splitPackages)
        ->mapWithKeys(function (array $definition) use ($root): array {
            $splitPackage = basename((string) $definition['path']);
            $composer = json_decode(
                file_get_contents($root . '/packages/' . $splitPackage . '/composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return [$composer['name'] => $composer['description'] ?? null];
        });

    expect($descriptions->get('capell-app/core'))->toBe('Laravel CMS content, publishing, extension, install, and upgrade foundations for Capell.')
        ->and($descriptions->get('capell-app/admin'))->toBe('Filament administration, page editing, recovery, settings, and operations for Capell CMS.')
        ->and($descriptions->get('capell-app/frontend'))->toBe('Public routing, rendering, themes, assets, and cache-safe delivery for Capell CMS.')
        ->and($descriptions->get('capell-app/installer'))->toBe('Guided browser and CLI installation for Capell CMS on Laravel.');

    $frontendCapellManifest = json_decode(
        file_get_contents($root . '/packages/frontend/capell.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($frontendCapellManifest['commands']['install'] ?? null)->toBe('capell:frontend-install');

    $splitWorkflow = file_get_contents($root . '/.github/workflows/split-monorepo.yml');
    $releaseSmokeWorkflow = file_get_contents($root . '/.github/workflows/public-release-smoke.yml');
    $releasePreflight = file_get_contents($root . '/scripts/release-preflight.php');
    $fastTestWorkflow = file_get_contents($root . '/.github/workflows/test-fast-pr.yml');
    $fullTestWorkflow = file_get_contents($root . '/.github/workflows/test-full.yml');
    $localSplitScript = file_get_contents($root . '/scripts/local-split-packages.sh');
    $packagistScript = file_get_contents($root . '/scripts/create-packagist-packages.sh');

    expect($splitWorkflow)
        ->toContain('actions: read')
        ->toContain('actions/create-github-app-token@bcd2ba49218906704ab6c1aa796996da409d3eb1')
        ->toContain('actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a')
        ->toContain('actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c')
        ->toContain('SPLIT_APP_ID')
        ->toContain('SPLIT_APP_PRIVATE_KEY')
        ->toContain('permission-contents: write')
        ->toContain('permission-workflows: write')
        ->toContain('persist-credentials: false')
        ->toContain('Configure split repository git credentials')
        ->toContain('url."https://x-access-token:${GH_TOKEN}@github.com/".insteadOf "https://github.com/"')
        ->toContain('workflow_dispatch:')
        ->toContain('plan_artifact:')
        ->toContain('PLAN_PATH: ${{ inputs.plan_path }}')
        ->toContain('test -n "${PLAN_PATH}"')
        ->toContain('realpath "${candidate}"')
        ->toContain('Plan path escapes the checked-out workspace.')
        ->toContain('plan_path: release-plan.json')
        ->toContain('resume_state_run_id:')
        ->toContain('run-id: ${{ inputs.resume_state_run_id }}')
        ->toContain('repository: ${{ github.repository }}')
        ->toContain('github-token: ${{ github.token }}')
        ->toContain('release-plan.json.state.json')
        ->toContain('Check out the approved plan source')
        ->toContain('git checkout --detach "${source_commit}"')
        ->toContain('if: always()')
        ->toContain('if-no-files-found: ignore')
        ->not->toContain('test -f "${{ inputs.plan_path }}"')
        ->toContain('php scripts/release.php resume')
        ->toContain('uses: ./.github/workflows/public-release-smoke.yml')
        ->not->toContain('release:')
        ->not->toContain('rollback')
        ->and($localSplitScript)->toContain('BRANCH="${CAPELL_SPLIT_BRANCH:-main}"')
        ->toContain('rollback_release_tags')
        ->toContain('git push "${remote_url}" ":refs/tags/${TAG}"')
        ->and($packagistScript)->toContain('PACKAGES+=("capell")')
        ->and($packagistScript)->toContain('--preflight')
        ->and($packagistScript)->toContain('repos/${repository}/contents/composer.json')
        ->and($packagistScript)->toContain('https://packagist.org/api/github')
        ->and($packagistScript)->toContain('Packagist preflight failed.');

    expect($releaseSmokeWorkflow)
        ->toContain('plan_path:')
        ->toContain('workflow_call:')
        ->not->toContain('workflow_dispatch:')
        ->toContain('cd "$(mktemp -d)"')
        ->toContain('${GITHUB_WORKSPACE}/scripts/release.php')
        ->toContain("jq -r '.packages[] | [.name, .proposed_version] | @tsv'")
        ->toContain('composer update --no-interaction')
        ->and($fastTestWorkflow)
        ->toContain('- main')
        ->toContain('"pestphp/pest:^4.3"')
        ->toContain('"filament/filament:~5.6.8"')
        ->not->toContain('filament/filament:^4.7')
        ->and($fullTestWorkflow)
        ->toContain('- main');

    expect($releasePreflight)
        ->toContain('[$major, $minor]')
        ->toContain('"dev-main as {$major}.{$minor}.x-dev"')
        ->toContain('php artisan capell:package-cache --no-interaction')
        ->toContain('npm install --no-audit --no-fund')
        ->toContain('npm run build')
        ->toContain('artisan serve --no-reload')
        ->not->toContain('--all-packages')
        ->not->toContain("'dev-main as ' . \$package['version']");

    expect($releaseSmokeWorkflow)->toContain('artisan serve --no-reload');

    expect($localSplitScript)->toContain('config/release-packages.json')
        ->and($packagistScript)->toContain('config/release-packages.json');
});

it('warms the package manifest cache after installation and before release smoke requests', function (): void {
    $releasePreflight = file_get_contents(dirname(__DIR__, 2) . '/scripts/release-preflight.php');

    expect($releasePreflight)
        ->toContain('php artisan capell:install')
        ->toContain('php artisan capell:package-cache')
        ->toContain('npm run build')
        ->toContain('php artisan serve --no-reload');

    $installIndex = strpos($releasePreflight, 'php artisan capell:install');
    $packageCacheIndex = strpos($releasePreflight, 'php artisan capell:package-cache');
    $assetBuildIndex = strpos($releasePreflight, 'npm run build');
    $serveIndex = strpos($releasePreflight, 'php artisan serve --no-reload');

    expect($installIndex)->toBeLessThan($packageCacheIndex)
        ->and($packageCacheIndex)->toBeLessThan($assetBuildIndex)
        ->and($assetBuildIndex)->toBeLessThan($serveIndex);
});

it('defines the paid root package as the aggregate of the public foundation', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(
        file_get_contents($root . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['name'])->toBe('capell-app/capell')
        ->and($manifest['license'])->toBe('proprietary')
        ->and($manifest['replace'])->toBe([
            'capell-app/admin' => 'self.version',
            'capell-app/core' => 'self.version',
            'capell-app/frontend' => 'self.version',
            'capell-app/installer' => 'self.version',
            'capell-app/marketplace' => 'self.version',
        ])
        ->and($manifest['autoload']['psr-4'])->toHaveKeys([
            'Capell\\Admin\\',
            'Capell\\Core\\',
            'Capell\\Frontend\\',
            'Capell\\Installer\\',
            'Capell\\Marketplace\\',
        ])
        ->and($manifest['extra']['laravel']['providers'])->toContain(
            CapellServiceProvider::class,
            AdminServiceProvider::class,
            FrontendServiceProvider::class,
            InstallerServiceProvider::class,
            MarketplaceServiceProvider::class,
        );
});

it('documents the verified dual installation paths without exposing a real credential', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = file_get_contents($root . '/README.md');
    $installGuide = file_get_contents($root . '/docs/getting-started/install.md');

    expect($readme)->toContain('composer require capell-app/installer')
        ->toContain('composer config repositories.capell composer https://capell.app/composer')
        ->toContain('composer config bearer.capell.app <short-lived-token>')
        ->toContain('composer require capell-app/capell')
        ->toContain('expires within 30 minutes')
        ->and($installGuide)->toContain('Owners, Billing members, and authorised technical members')
        ->toContain('stored only as a hash by Capell')
        ->toContain('keep that file out of source control')
        ->not->toMatch('/capell_membership_[A-Za-z0-9]{20,}/');
});

it('documents one public distribution and commercial licensing story', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = file_get_contents($root . '/README.md');
    $quickstart = file_get_contents($root . '/docs/getting-started/quickstart.md');
    $install = file_get_contents($root . '/docs/getting-started/install.md');
    $supportPolicy = file_get_contents($root . '/packages/core/README.md');

    expect($readme)
        ->toContain('Foundation packages install from public Packagist repositories.')
        ->toContain('For the shipped 1.x line')
        ->toContain('Capell is commercial software (`"license": "proprietary"`)')
        ->toContain('Public visibility does not change the Capell licence.')
        ->not->toContain('not published through a public source repository')
        ->not->toContain('distributed through private Composer access')
        ->and($quickstart)
        ->toContain('current 1.x foundation release is available through public Packagist packages')
        ->and($install)
        ->toContain('Install the public foundation')
        ->toContain('Paid marketplace packages use authenticated Composer access')
        ->not->toContain('Configure private Capell access')
        ->not->toContain('Do not substitute public Packagist')
        ->and($supportPolicy)
        ->toContain('Each Capell 1.x minor receives security fixes for 24 months from its release date')
        ->toContain('the latest 1.x minor is always supported');

    foreach ([$readme, $quickstart, $install, $supportPolicy] as $publicDocument) {
        expect($publicDocument)->not->toContain('0.0.x');
    }
});

it('defines the root package as the aligned aggregate of the foundation', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(
        file_get_contents($root . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['name'])->toBe('capell-app/capell')
        ->and($manifest['license'])->toBe('proprietary')
        ->and($manifest['replace'])->toBe([
            'capell-app/admin' => 'self.version',
            'capell-app/core' => 'self.version',
            'capell-app/frontend' => 'self.version',
            'capell-app/installer' => 'self.version',
            'capell-app/marketplace' => 'self.version',
        ])
        ->and($manifest['autoload']['psr-4'])->toHaveKeys([
            'Capell\\Admin\\',
            'Capell\\Core\\',
            'Capell\\Frontend\\',
            'Capell\\Installer\\',
            'Capell\\Marketplace\\',
        ])
        ->and($manifest['extra']['laravel']['providers'])->toContain(
            CapellServiceProvider::class,
            AdminServiceProvider::class,
            FrontendServiceProvider::class,
            InstallerServiceProvider::class,
            MarketplaceServiceProvider::class,
        );
});

it('keeps split package readmes standalone', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (['admin', 'core', 'frontend', 'installer', 'marketplace'] as $package) {
        $readme = file_get_contents($root . '/packages/' . $package . '/README.md');
        $releaseBadge = '[![Latest Release](https://img.shields.io/github/v/release/capell-app/' . $package
            . '?style=flat-square&label=release)](https://github.com/capell-app/' . $package . '/releases/latest)';

        expect($readme)
            ->not->toContain('../../docs/')
            ->not->toContain('packages/' . $package . '/')
            ->not->toContain('vendor/bin/pest packages/' . $package . '/')
            ->toContain("```bash\nvendor/bin/pest tests\n```")
            ->toContain($releaseBadge)
            ->not->toContain('github/v/release/capell-app/capell?')
            ->not->toContain('github.com/capell-app/capell/releases/latest')
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
    $docs = glob($root . '/docs/{frontend,packages,performance,operations,development,platform}/*.md', GLOB_BRACE) ?: [];

    expect($docs)->not->toBeEmpty();

    foreach ($docs as $doc) {
        expect((string) file_get_contents($doc))->not->toMatch($recycledHeroPattern);
    }
});
