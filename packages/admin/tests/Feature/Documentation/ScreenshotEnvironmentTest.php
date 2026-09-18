<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Facades\File;
use Workbench\App\Support\ScreenshotEnvironment;

beforeEach(function (): void {
    app()->instance('env', 'production');
    config(['app.debug' => false, 'app.url' => 'https://capell.example', 'session.driver' => 'file']);
    $this->originalPublicPath = public_path();
    app()->usePublicPath(storage_path('framework/testing/screenshot-environment-public'));
    File::ensureDirectoryExists(public_path('build/filament'));
    File::put(public_path('build/filament/theme.css'), '.fi-body { color: #111; }');
});

afterEach(function (): void {
    File::deleteDirectory(public_path());
    app()->usePublicPath($this->originalPublicPath);
});

it('accepts a prepared capture environment', function (): void {
    SiteDomain::factory()->default()->create(['domain' => 'capell.example', 'status' => true]);
    ScreenshotEnvironment::verify();
    expect(config('app.debug'))->toBeFalse();
});

it('rejects an old loopback fixture database', function (): void {
    SiteDomain::factory()->default()->create(['domain' => '127.0.0.1', 'status' => true]);
    expect(fn () => ScreenshotEnvironment::verify())->toThrow(RuntimeException::class, 'stale display domain');
});

it('rejects an unstyled admin placeholder', function (): void {
    SiteDomain::factory()->default()->create(['domain' => 'capell.example', 'status' => true]);
    File::put(public_path('build/filament/theme.css'), '/* Build the real theme. */');
    expect(fn () => ScreenshotEnvironment::verify())->toThrow(RuntimeException::class, 'has not been compiled');
});

it('rejects debug output and nonpersistent sessions', function (string $key, mixed $value): void {
    config([$key => $value]);
    expect(fn () => ScreenshotEnvironment::verify())->toThrow(RuntimeException::class, 'persistent file sessions');
})->with([
    'debug' => ['app.debug', true],
    'session' => ['session.driver', 'array'],
]);
