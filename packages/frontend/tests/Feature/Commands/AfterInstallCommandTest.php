<?php

declare(strict_types=1);

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    resetFrontendAfterInstallVendorAssets();
});

afterEach(function (): void {
    resetFrontendAfterInstallVendorAssets();
});

function resetFrontendAfterInstallVendorAssets(): void
{
    $manager = CapellCore::getFacadeRoot();
    $property = new ReflectionProperty($manager, 'vendorAssets');
    $property->setValue($manager, []);
}

/**
 * AfterInstallCommand (capell:frontend-after-install) tests.
 *
 * The command has no database side effects — it generates Tailwind asset
 * files, optionally installs npm dependencies, and optionally runs an npm
 * build. Tests use a fake 'capell.tailwind.generator' binding so
 * the test env does not need the foundation-theme package installed.
 *
 * The command requires interactive input for confirmations (it calls
 * requireInteractiveOrFail() after generating assets). Tests use
 * expectsConfirmation() so the Artisan test helper treats the session
 * as interactive and feeds scripted answers.
 *
 * GAPS (not tested):
 * - npm dependency installation: requires a real npm env and process runner.
 * - npm build step: same reason.
 */

/**
 * Create and bind a fake tailwind generator that writes a CSS file at
 * $assetPath and returns that path when generate() is called.
 */
function bindFakeTailwindGenerator(string $assetPath): void
{
    app()->bind('capell.tailwind.generator', fn (): object => new readonly class($assetPath)
    {
        public function __construct(private string $assetFilePath) {}

        /** @return array<string> */
        public function generate(?string $overridePath = null): array
        {
            $targetPath = $overridePath ?? $this->assetFilePath;
            File::ensureDirectoryExists(dirname($targetPath));
            File::put($targetPath, '/* generated tailwind assets */');

            return [$targetPath];
        }
    });
}

// ---------------------------------------------------------------------------
it('throws in non-interactive mode when build confirmation is required', function (): void {
    $tmpAssetPath = sys_get_temp_dir() . '/capell_test_assets_ni_' . uniqid() . '.css';
    bindFakeTailwindGenerator($tmpAssetPath);

    // --no-interaction forces isInteractive() === false.
    // requireInteractiveOrFail() must throw before reaching the build confirm() call.
    expect(fn () => Artisan::call('capell:frontend-after-install', [
        '--no-interaction' => true,
    ]))->toThrow(RuntimeException::class, 'Run this command interactively to confirm the npm build.');

    File::delete($tmpAssetPath);
});

it('generates the package css and exits successfully when the user declines npm build', function (): void {
    $tmpDir = sys_get_temp_dir() . '/capell_test_decline_' . uniqid();
    $tmpAssetPath = $tmpDir . '/generated.css';
    File::ensureDirectoryExists($tmpDir);
    bindFakeTailwindGenerator($tmpAssetPath);

    artisanCommand('capell:frontend-after-install')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
        ->assertExitCode(0);

    expect(File::exists($tmpAssetPath))->toBeTrue();

    File::deleteDirectory($tmpDir);
});

it('adds generated capell css to vite inputs before build', function (): void {
    $tmpDir = base_path('resources/css/capell_test_' . uniqid());
    $tmpAssetPath = $tmpDir . '/frontend.css';
    $viteConfigPath = base_path('vite.config.js');
    $originalViteConfig = File::exists($viteConfigPath) ? File::get($viteConfigPath) : null;

    File::ensureDirectoryExists($tmpDir);
    bindFakeTailwindGenerator($tmpAssetPath);

    File::put($viteConfigPath, <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
JS);

    try {
        artisanCommand('capell:frontend-after-install')
            ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
            ->assertExitCode(0);

        expect(File::get($viteConfigPath))
            ->toContain("'resources/css/app.css'")
            ->toContain("'resources/js/app.js'")
            ->toContain('"resources/css/' . basename($tmpDir) . '/frontend.css"');
    } finally {
        if ($originalViteConfig === null) {
            File::delete($viteConfigPath);
        } else {
            File::put($viteConfigPath, $originalViteConfig);
        }

        File::deleteDirectory($tmpDir);
    }
});

it('does not update vite inputs for non-vite asset builds', function (): void {
    config()->set('capell-frontend.asset_build_tool', 'static');

    $tmpDir = base_path('resources/css/capell_test_' . uniqid());
    $tmpAssetPath = $tmpDir . '/frontend.css';
    $viteConfigPath = base_path('vite.config.js');
    $originalViteConfig = File::exists($viteConfigPath) ? File::get($viteConfigPath) : null;

    File::ensureDirectoryExists($tmpDir);
    bindFakeTailwindGenerator($tmpAssetPath);

    File::put($viteConfigPath, <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
JS);

    try {
        artisanCommand('capell:frontend-after-install')
            ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
            ->assertExitCode(0);

        expect(File::get($viteConfigPath))
            ->toContain("'resources/css/app.css'")
            ->toContain("'resources/js/app.js'")
            ->not->toContain("'resources/css/" . basename($tmpDir) . "/frontend.css'");
    } finally {
        if ($originalViteConfig === null) {
            File::delete($viteConfigPath);
        } else {
            File::put($viteConfigPath, $originalViteConfig);
        }

        File::deleteDirectory($tmpDir);
    }
});

it('installs declared npm dependencies before declining the build step', function (): void {
    $tmpDir = sys_get_temp_dir() . '/capell_test_npm_deps_' . uniqid();
    $tmpAssetPath = $tmpDir . '/generated.css';
    File::ensureDirectoryExists($tmpDir);
    bindFakeTailwindGenerator($tmpAssetPath);

    CapellCore::registerVendorAsset(VendorAssetData::npmDependency('swiper', '^11.0.0'));
    CapellCore::registerVendorAsset(VendorAssetData::npmDependency('@tailwindcss/forms', '^0.5.10'));
    CapellCore::registerVendorAsset(VendorAssetData::npmDependency('', '^1.0.0'));
    CapellCore::registerVendorAsset(new VendorAssetData(VendorAssetEnum::NpmDependency, 'ignored-package'));

    Process::fake(fn () => Process::result('installed'));

    try {
        artisanCommand('capell:frontend-after-install')
            ->expectsOutputToContain('The following npm packages are required by installed Capell packages:')
            ->expectsOutputToContain('- swiper@^11.0.0')
            ->expectsOutputToContain('- @tailwindcss/forms@^0.5.10')
            ->expectsConfirmation('Would you like to install these npm packages now?', 'yes')
            ->expectsOutputToContain('npm packages installed successfully.')
            ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
            ->assertExitCode(0);

        Process::assertRan(fn ($process): bool => $process->command === [
            'npm',
            'install',
            'swiper@^11.0.0',
            '@tailwindcss/forms@^0.5.10',
        ]);
    } finally {
        File::deleteDirectory($tmpDir);
    }
});

it('reports npm dependency install failures without running the build', function (): void {
    $tmpDir = sys_get_temp_dir() . '/capell_test_npm_deps_failed_' . uniqid();
    $tmpAssetPath = $tmpDir . '/generated.css';
    File::ensureDirectoryExists($tmpDir);
    bindFakeTailwindGenerator($tmpAssetPath);

    CapellCore::registerVendorAsset(VendorAssetData::npmDependency('swiper', '^11.0.0'));

    Process::fake(fn () => Process::result(output: 'npm failed', exitCode: 1));

    try {
        artisanCommand('capell:frontend-after-install')
            ->expectsConfirmation('Would you like to install these npm packages now?', 'yes')
            ->expectsOutputToContain('Failed to install npm packages.')
            ->expectsOutputToContain('npm failed')
            ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($tmpDir);
    }
});

it('explains common npm build failures when the user runs a development build', function (): void {
    $tmpDir = sys_get_temp_dir() . '/capell_test_build_failure_' . uniqid();
    $tmpAssetPath = $tmpDir . '/generated.css';
    File::ensureDirectoryExists($tmpDir);
    bindFakeTailwindGenerator($tmpAssetPath);

    Process::fake(fn () => Process::result(
        errorOutput: "Can't resolve 'swiper/css'\nENOENT: no such file or directory, open 'resources/css/missing.css'",
        exitCode: 1,
    ));

    try {
        artisanCommand('capell:frontend-after-install', ['--dev' => true])
            ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'yes')
            ->expectsOutputToContain('Running: npm run dev')
            ->expectsOutputToContain('npm build failed.')
            ->expectsOutputToContain('Hint: Swiper CSS paths failed to resolve.')
            ->expectsOutputToContain('Hint: A file referenced by the build could not be found.')
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($tmpDir);
    }
});

it('rejects unsafe npm dependency names before invoking npm install', function (): void {
    $tmpDir = sys_get_temp_dir() . '/capell_test_invalid_npm_' . uniqid();
    $tmpAssetPath = $tmpDir . '/generated.css';
    File::ensureDirectoryExists($tmpDir);
    bindFakeTailwindGenerator($tmpAssetPath);

    CapellCore::registerVendorAsset(VendorAssetData::npmDependency('bad package', '^1.0.0'));
    Process::fake();

    try {
        expect(fn () => artisanCommand('capell:frontend-after-install')
            ->expectsConfirmation('Would you like to install these npm packages now?', 'yes')
            ->run())
            ->toThrow(RuntimeException::class, 'Invalid npm package name "bad package".');

        Process::assertNothingRan();
    } finally {
        File::deleteDirectory($tmpDir);
    }
});
