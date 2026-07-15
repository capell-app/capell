<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\ViteThemeInputPatch;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->testDirectory = sys_get_temp_dir() . '/capell-vite-theme-input-patch-test-' . uniqid();

    File::ensureDirectoryExists($this->testDirectory);
    $this->app->setBasePath($this->testDirectory);
});

afterEach(function (): void {
    if (is_dir($this->testDirectory)) {
        File::deleteDirectory($this->testDirectory);
    }
});

it('registers the Filament theme in a standard Laravel Vite input array', function (): void {
    file_put_contents(base_path('vite.config.js'), <<<'JS'
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

    $patch = new ViteThemeInputPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and((string) file_get_contents(base_path('vite.config.js')))
        ->toContain("input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css']");
});

it('preserves an existing Filament theme input', function (): void {
    file_put_contents(
        base_path('vite.config.js'),
        "export default { input: ['resources/css/filament/admin/theme.css'] };",
    );

    expect((new ViteThemeInputPatch)->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('does not rewrite a customised Vite configuration without a Laravel input array', function (): void {
    file_put_contents(base_path('vite.config.js'), 'export default defineConfig({ plugins: [] });');

    expect((new ViteThemeInputPatch)->probe())->toBe(PatchStatus::Customised);
});

it('is available to the web install guide patch registry', function (): void {
    expect(resolve(PatchRegistry::class)->get('vite-theme-input-patch'))
        ->toBeInstanceOf(ViteThemeInputPatch::class);
});

it('is contributed to the Core install patch registry for Admin installs', function (): void {
    $patches = resolve(InstallPatchRegistry::class)->patchesFor(new InstallPatchContext(
        packageNames: ['capell-app/admin'],
        hasFilamentAdminPanelProvider: true,
    ));

    expect(collect($patches)->pluck('patch')->map->id()->all())
        ->toContain('vite-theme-input-patch');
});
