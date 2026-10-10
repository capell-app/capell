<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Capell\Tests\Support\CommandFixture;
use Composer\Semver\Semver;
use Symfony\Component\Yaml\Yaml;

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
        ->and($marketplaceManifest['require']['capell-app/admin'])->toBe('^1.0')
        ->and($marketplaceManifest['require']['capell-app/core'])->toBe('^1.0');

    foreach (['admin', 'frontend', 'installer'] as $foundationPackage) {
        $manifest = json_decode(
            file_get_contents($root . '/packages/' . $foundationPackage . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($manifest['require']['capell-app/core'])->toBe('^1.0');
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

});

it('gates publication on the approved plan and runs the reusable smoke after publication', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile($root . '/.github/workflows/split-monorepo.yml');
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch'])
        ->and($workflow['permissions'])->toMatchArray(['actions' => 'read', 'contents' => 'write'])
        ->and($workflow['jobs']['publish']['needs'])->toBe('prepare-plan')
        ->and($workflow['jobs']['public-release-smoke'])->toMatchArray([
            'needs' => 'publish', 'uses' => './.github/workflows/public-release-smoke.yml',
            'with' => ['plan_path' => 'release-plan.json', 'plan_artifact' => 'approved-release-plan'],
        ]);
    $steps = array_column($workflow['jobs']['publish']['steps'], null, 'name');
    expect($steps['Create split repository token']['with'])->toMatchArray([
        'permission-contents' => 'write', 'permission-workflows' => 'write',
        'repositories' => 'admin,core,frontend,installer,marketplace',
    ]);
    /** @var array<string, mixed> $smoke */
    $smoke = Yaml::parseFile($root . '/.github/workflows/public-release-smoke.yml');
    expect(array_keys($smoke['on']))->toBe(['workflow_call'])
        ->and($smoke['on']['workflow_call']['secrets']['ACCESS_TOKEN']['required'])->toBeTrue()
        ->and($smoke['jobs'])->toHaveKey('n-minus-one-upgrade');
});

it('accepts only workspace-relative release plans before staging them for approval', function (string $path, bool $accepted): void {
    $fixture = new CommandFixture;
    $fixture->files->write('release-plan.json', '{"approved":true}');
    $fixture->files->write('staged/.keep', '');
    $fixture->fake('php');
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/split-monorepo.yml');
    $steps = array_column($workflow['jobs']['prepare-plan']['steps'], null, 'name');
    try {
        $process = $fixture->run(['bash', '-e', '-c', $steps['Validate committed release plan']['run']], [
            'PLAN_PATH' => $path, 'GITHUB_WORKSPACE' => $fixture->files->root, 'RUNNER_TEMP' => $fixture->files->root . '/staged',
        ]);
        expect($process->getExitCode() === 0)->toBe($accepted);
        if ($accepted) {
            expect(json_decode((string) file_get_contents($fixture->files->root . '/staged/release-plan.json'), true, flags: JSON_THROW_ON_ERROR))->toBe(['approved' => true]);
        } else {
            expect($fixture->files->root . '/staged/release-plan.json')->not->toBeFile()
                ->and($fixture->calls('php'))->toBe([]);
        }
    } finally {
        $fixture->close();
    }
})->with([
    ['release-plan.json', true], ['', false], ['/release-plan.json', false],
    ['../release-plan.json', false], ['./release-plan.json', false],
]);

it('splits only catalogue packages and removes tags already published when a later split fails', function (): void {
    $fixture = new CommandFixture;
    $fixture->files->copy('scripts/local-split-packages.sh');
    $fixture->files->copy('config/release-packages.json');
    foreach (['core', 'admin'] as $package) {
        $fixture->files->write('packages/' . $package . '/composer.json', '{}');
    }

    $fixture->fake('git', 'if (in_array("subtree", $argv, true)) { echo "split-result"; }');
    // The boundary fails the second split; no repository or remote is touched.
    $fixture->files->write('bin/git-failure', <<<'BASH'
        #!/usr/bin/env bash
        if [[ "$*" == *'subtree split --prefix packages/admin'* ]]; then
            exit 19
        fi
        exec "$(dirname "$0")/git-recorder" "$@"
        BASH);
    rename($fixture->files->root . '/bin/git', $fixture->files->root . '/bin/git-recorder');
    rename($fixture->files->root . '/bin/git-failure', $fixture->files->root . '/bin/git');
    chmod($fixture->files->root . '/bin/git', 0755);
    try {
        $process = $fixture->run(['bash', 'scripts/local-split-packages.sh', '--tag', 'v1.2.3', '--package', 'core', '--package', 'admin', '--remote-template', 'fixture://%s']);
        expect($process->getExitCode())->toBe(19)
            ->and(array_column($fixture->calls('git-recorder'), 'arguments'))->toContain(
                ['push', 'fixture://core', 'split-result:refs/heads/main'],
                ['push', 'fixture://core', 'split-result:refs/tags/v1.2.3'],
                ['push', 'fixture://core', ':refs/tags/v1.2.3'],
            );
    } finally {
        $fixture->close();
    }
});

it('runs the release validator without a Laravel bootstrap', function (): void {
    $root = dirname(__DIR__, 2);
    $output = [];
    $exitCode = 1;

    exec(sprintf(
        '%s %s validate %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/scripts/release.php'),
        escapeshellarg($root . '/release-plan.json'),
    ), $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode(PHP_EOL, $output))->toContain('Plan is valid.');
});

it('reads public Packagist package slugs from the Packagist catalogue', function (): void {
    $root = dirname(__DIR__, 2);
    $catalogue = json_decode(
        file_get_contents($root . '/config/packagist-packages.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $temporary = sys_get_temp_dir() . '/capell-packagist-test-' . bin2hex(random_bytes(8));
    mkdir($temporary, 0700, true);
    file_put_contents($temporary . '/curl', "#!/usr/bin/env bash\nprintf '404'\n");
    chmod($temporary . '/curl', 0700);

    $output = [];
    $exitCode = 1;

    try {
        exec(sprintf(
            'PATH=%s bash %s --dry-run 2>&1',
            escapeshellarg($temporary . ':' . getenv('PATH')),
            escapeshellarg($root . '/scripts/create-packagist-packages.sh'),
        ), $output, $exitCode);
    } finally {
        unlink($temporary . '/curl');
        rmdir($temporary);
    }

    $result = implode(PHP_EOL, $output);

    expect($exitCode)->toBe(0)
        ->and($catalogue['packages'])->toBe([
            'capell',
            'core',
            'admin',
            'frontend',
            'installer',
            'marketplace',
            'block-library',
            'filament-peek',
            'html-cache',
            'layout-builder',
            'media-library',
            'navigation',
            'site-stats',
            'theme-foundation',
            'theme-liquid-glass',
            'welcome-tour',
        ])
        ->and($result)->toContain('Create capell-app/core')
        ->toContain('Create capell-app/admin')
        ->toContain('Create capell-app/frontend')
        ->toContain('Create capell-app/installer')
        ->toContain('Create capell-app/marketplace')
        ->toContain('Create capell-app/capell')
        ->toContain('Create capell-app/block-library')
        ->toContain('Create capell-app/filament-peek')
        ->toContain('Create capell-app/html-cache')
        ->toContain('Create capell-app/layout-builder')
        ->toContain('Create capell-app/media-library')
        ->toContain('Create capell-app/navigation')
        ->toContain('Create capell-app/site-stats')
        ->toContain('Create capell-app/theme-foundation')
        ->toContain('Create capell-app/theme-liquid-glass')
        ->toContain('Create capell-app/welcome-tour')
        ->not->toContain('Create capell-app/password-policy')
        ->not->toContain('Create capell-app/publishing-studio')
        ->not->toContain('Array to string conversion')
        ->not->toContain('capell-app/Array');
});

it('defines the MIT root package as the aggregate of the public foundation', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(
        file_get_contents($root . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['name'])->toBe('capell-app/capell')
        ->and($manifest['license'])->toBe('MIT')
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
        ->toContain('https://capell.app/composer')
        ->toContain('short-lived credential')
        ->toContain('capell:install --spec=')
        ->toContain('expires 30 minutes after it is issued')
        ->and($installGuide)->toContain('Owners, Billing members, and members with private Composer access')
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
        ->toContain('Capell Foundation is MIT-licensed and installs from public Packagist repositories without a Capell account.')
        ->toContain('For the shipped 1.x line')
        ->toContain('Capell Foundation is MIT-licensed')
        ->toContain('Paid marketplace packages remain commercially licensed')
        ->not->toContain('not published through a public source repository')
        ->not->toContain('distributed through private Composer access')
        ->and($quickstart)
        ->toContain('current 1.x Capell Foundation release is MIT-licensed and available through public Packagist packages without a Capell account')
        ->and($install)
        ->toContain('Install the public foundation')
        ->toContain('Paid marketplace packages use separate commercial terms and entitlement-scoped Composer access')
        ->not->toContain('Configure private Capell access')
        ->not->toContain('Do not substitute public Packagist')
        ->and($supportPolicy)
        ->toContain('Each Capell 1.x minor receives security fixes for 24 months from its release date')
        ->toContain('the latest 1.x minor is always supported');

    foreach ([$readme, $quickstart, $install, $supportPolicy] as $publicDocument) {
        expect($publicDocument)->not->toContain('0.0.x');
    }
});

it('keeps Foundation AI claims separate from optional delivery packages', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = file_get_contents($root . '/README.md');
    $aiReadyGuide = file_get_contents($root . '/docs/getting-started/ai-ready.md');

    expect($readme)
        ->toContain('optional packages add focused capabilities when the project earns')
        ->not->toContain('Publish your pages to answer engines as clean, RAG-ready JSON')
        ->and($aiReadyGuide)
        ->toContain('Capell Foundation gives optional AI packages structured, permissioned CMS context and a safe public-rendering boundary before a provider is added.')
        ->not->toContain('Capell is AI-ready because')
        ->not->toContain('`llms.txt`');
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
        ->and($manifest['license'])->toBe('MIT')
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

it('includes the MIT licence in every foundation split', function (): void {
    $root = dirname(__DIR__, 2);
    $foundationPackages = ['core', 'admin', 'frontend', 'installer', 'marketplace'];

    foreach ($foundationPackages as $package) {
        $packageRoot = $root . '/packages/' . $package;
        $packageManifest = json_decode(
            file_get_contents($packageRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($packageManifest['license'])->toBe('MIT')
            ->and(file_get_contents($packageRoot . '/LICENSE.md'))
            ->toContain('Permission is hereby granted, free of charge');
    }
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

it('generates changelog notes for empty or placeholder releases and rejects empty generated notes', function (string $body, string $generated, ?string $expected): void {
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/update-changelog.yml');
    $steps = array_column($workflow['jobs']['update']['steps'], null, 'name');
    $fixture = new CommandFixture;
    $fixture->files->write('notes.cjs', <<<'JS_WRAP'
    const fs = require('node:fs');
    const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
    const outputs = {}; const failures = [];
    const core = { setOutput: (key, value) => outputs[key] = value, setFailed: message => failures.push(message), info: () => {} };
    const context = { payload: { release: { tag_name: 'v1.2.3', body: fixture.body, published_at: '2026-01-01T12:00:00Z' } }, repo: { owner: 'example', repo: 'core' } };
    const github = { rest: { repos: { generateReleaseNotes: async () => ({ data: { body: fixture.generated } }) } } };
    const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
    new AsyncFunction('core', 'context', 'github', fixture.script)(core, context, github).then(() => console.log(JSON.stringify({outputs, failures})));
    JS_WRAP);
    $fixture->files->write('fixture.json', json_encode(['body' => $body, 'generated' => $generated, 'script' => $steps['Prepare changelog inputs']['with']['script']], JSON_THROW_ON_ERROR));
    try {
        $process = $fixture->run(['node', 'notes.cjs', 'fixture.json']);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if ($expected === null) {
            expect($result['failures'])->toBe(['Release notes are empty after generation.'])
                ->and($result['outputs'])->toBe([]);
        } else {
            expect($result['failures'])->toBe([])
                ->and($result['outputs'])->toMatchArray(['latest_version' => 'v1.2.3', 'release_notes' => $expected, 'release_date' => '2026-01-01']);
        }
    } finally {
        $fixture->close();
    }
})->with([
    ['', 'Added useful feature', 'Added useful feature'],
    ['Release v1.2.3 for Capell 4.x.', 'Fixed navigation', 'Fixed navigation'],
    ['Existing useful notes', 'Unused generated notes', 'Existing useful notes'],
    ['', '', null],
]);

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
