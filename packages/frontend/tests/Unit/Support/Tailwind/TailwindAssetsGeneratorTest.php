<?php

declare(strict_types=1);

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Frontend\Support\Tailwind\TailwindAssetsGenerator;
use Capell\Frontend\Tests\Unit\Support\Tailwind\Fixtures\TailwindAssetsErrProvider;
use Capell\Frontend\Tests\Unit\Support\Tailwind\Fixtures\TailwindAssetsOkProvider;
use Illuminate\Support\Facades\File;

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
    CapellCore::registerVendorAsset(new VendorAssetData(VendorAssetEnum::TailwindImport, '', packageName: null));

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
