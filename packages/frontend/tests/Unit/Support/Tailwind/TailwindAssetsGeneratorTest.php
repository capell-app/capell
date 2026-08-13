<?php

declare(strict_types=1);

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Frontend\Support\Tailwind\TailwindAssetsGenerator;
use Capell\Frontend\Tests\Unit\Support\Tailwind\Fixtures\TailwindAssetsErrProvider;
use Capell\Frontend\Tests\Unit\Support\Tailwind\Fixtures\TailwindAssetsOkProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    resetFrontendTailwindVendorAssets();
});

afterEach(function (): void {
    resetFrontendTailwindVendorAssets();
});

function resetFrontendTailwindVendorAssets(): void
{
    $manager = CapellCore::getFacadeRoot();
    $property = new ReflectionProperty($manager, 'vendorAssets');
    $property->setValue($manager, []);
}

it('binds the frontend tailwind generator without foundation installed', function (): void {
    expect(app()->bound('capell.tailwind.generator'))->toBeTrue()
        ->and(resolve('capell.tailwind.generator'))->toBeInstanceOf(TailwindAssetsGenerator::class)
        ->and(resolve(TailwindAssetsGenerator::class))->toBeInstanceOf(TailwindAssetsGenerator::class);
});

it('reports frontend-owned tailwind assets from the command', function (): void {
    config()->set('capell-frontend.tailwind.sources', ['resources/views/**/*.blade.php']);

    artisanCommand('capell:frontend-tailwind-assets', ['--report' => true])
        ->expectsOutputToContain('Tailwind assets report:')
        ->expectsOutputToContain('config:capell-frontend.tailwind')
        ->assertExitCode(0);

    expect(resolve(TailwindAssetsGenerator::class)->collect()->themeColors())
        ->toHaveKey('default-accent');
});

it('generates css from config, vendor assets, provider assets, and default theme colors', function (): void {
    $targetDirectory = storage_path('framework/testing/capell_frontend_tailwind_' . uniqid());
    $targetPath = $targetDirectory . '/frontend.css';

    config()->set('capell-frontend.tailwind.imports', ['@acme/base.css']);
    config()->set('capell-frontend.tailwind.plugins', ['@tailwindcss/typography']);
    config()->set('capell-frontend.tailwind.sources', ['resources/views/**/*.blade.php']);

    CapellCore::registerVendorAsset(VendorAssetData::tailwindImport('resources/css/global.css'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindPlugin('@tailwindcss/forms'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindSource('resources/views/components/**/*.blade.php'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindThemeColor('brand-primary', '#0f766e'));
    CapellCore::registerVendorAsset(new VendorAssetData(VendorAssetEnum::TailwindImport, ''));

    app()->register(TailwindAssetsOkProvider::class);

    try {
        $paths = resolve(TailwindAssetsGenerator::class)->generate($targetPath);

        expect($paths)->toBe([$targetPath])
            ->and(File::get($targetPath))
            ->toContain('@import "tailwindcss";')
            ->toContain('@import "@acme/base.css";')
            ->toContain('@plugin "@tailwindcss/forms";')
            ->toContain('@plugin "@tailwindcss/typography";')
            ->toContain('@source "./views/**/*.blade.php";')
            ->toContain('--color-brand-primary: #0f766e;')
            ->toContain('--color-default-accent: #2563eb;')
            ->not->toContain('foundation-theme');
    } finally {
        File::deleteDirectory($targetDirectory);
    }
});

it('fails loudly when a tailwind asset provider cannot register assets', function (): void {
    $targetDirectory = storage_path('framework/testing/capell_frontend_tailwind_' . uniqid());
    $targetPath = $targetDirectory . '/frontend.css';

    app()->register(TailwindAssetsErrProvider::class);

    try {
        expect(fn (): array => resolve(TailwindAssetsGenerator::class)->generate($targetPath))
            ->toThrow(RuntimeException::class, 'Failed to register Tailwind assets from provider');
    } finally {
        File::deleteDirectory($targetDirectory);
    }
});

it('rejects frontend tailwind output paths outside the project', function (): void {
    $outsidePath = dirname(base_path()) . '/capell_frontend_tailwind_' . uniqid() . '.css';

    expect(fn (): array => resolve(TailwindAssetsGenerator::class)->generate($outsidePath))
        ->toThrow(InvalidArgumentException::class, 'Tailwind output CSS path must stay inside the project.');
});

it('rejects frontend tailwind output path traversal from the command', function (): void {
    expect(fn (): int => artisanCommand('capell:frontend-tailwind-assets', [
        '--output-path' => '../capell_frontend_tailwind_' . uniqid() . '.css',
    ])->run())
        ->toThrow(InvalidArgumentException::class, 'Tailwind output CSS path must stay inside the project.');
});

it('keeps package-owned assets out of the generated registry until the package is installed', function (): void {
    CapellCore::registerVendorAsset(VendorAssetData::tailwindImport('@vendor/theme', 'vendor/not-installed'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindSource('resources/views/**/*.blade.php', 'vendor/not-installed'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindPlugin('@vendor/plugin', 'vendor/not-installed'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindImport('@vendor/installed', 'vendor/installed'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindSource('resources/views/installed/**/*.blade.php', 'vendor/installed'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindPlugin('@vendor/installed-plugin', 'vendor/installed'));
    CapellCore::forcePackageInstalled('vendor/installed');

    $registry = resolve(TailwindAssetsGenerator::class)->collect();
    $sources = $registry->sources()->all();

    expect($registry->imports()->all())->toContain('@vendor/installed')
        ->and($registry->imports()->all())->not->toContain('@vendor/theme')
        ->and($registry->plugins()->all())->toContain('@vendor/installed-plugin')
        ->and($registry->plugins()->all())->not->toContain('@vendor/plugin')
        ->and(array_filter($sources, static fn (string $source): bool => str_contains($source, 'vendor/installed/resources/views/installed/')))
        ->not->toBeEmpty()
        ->and(array_filter($sources, static fn (string $source): bool => str_contains($source, 'vendor/not-installed/')))
        ->toBe([]);
});

it('filters unsafe theme colors before rendering and records the rejection', function (): void {
    Log::spy();
    CapellCore::registerVendorAsset(VendorAssetData::tailwindThemeColor('safe', 'rgb(15 118 110 / 80%)'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindThemeColor('unsafe-name', '#0f766e; } @import url(https://evil.test)'));
    CapellCore::registerVendorAsset(VendorAssetData::tailwindThemeColor('unsafe-value', 'var(--missing);'));

    $colors = resolve(TailwindAssetsGenerator::class)->collect()->themeColors();

    expect($colors->all())->toHaveKey('safe')
        ->and($colors->all())->not->toHaveKeys(['unsafe-name', 'unsafe-value']);

    Log::shouldHaveReceived('warning')->twice();
});
