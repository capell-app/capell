<?php

declare(strict_types=1);

use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalStoragePath = app()->storagePath();
    $this->isolatedStoragePath = sys_get_temp_dir() . '/capell-manifest-store-' . bin2hex(random_bytes(6));
    app()->useStoragePath($this->isolatedStoragePath);
    config()->set('capell-frontend.static_artifacts_path');
});

afterEach(function (): void {
    app()->useStoragePath($this->originalStoragePath);
    File::deleteDirectory($this->isolatedStoragePath);
});

it('writes and reads manifests through the shared JSON codec', function (): void {
    $maintenanceStore = new MaintenanceManifestStore;
    $maintenanceStore->write(['fallback' => '/maintenance/fallback.html']);

    $staticStore = new StaticPageArtifactStore;
    $staticStore->writeManifest([
        'generated_at' => '2026-07-19T00:00:00+00:00',
        'artifacts' => [['url' => 'https://example.test/about']],
    ]);

    expect($maintenanceStore->read()['fallback'])->toBe('/maintenance/fallback.html')
        ->and(File::get($maintenanceStore->path()))->toContain('"/maintenance/fallback.html"')
        ->and($staticStore->readManifest()['artifacts'])->toBe([['url' => 'https://example.test/about']])
        ->and(File::get($staticStore->manifestPath()))->toContain('"https://example.test/about"');
});

it('rejects invalid UTF-8 without writing corrupt manifests', function (): void {
    $maintenanceStore = new MaintenanceManifestStore;
    $staticStore = new StaticPageArtifactStore;
    $invalidUtf8 = "\xB1\x31";

    expect(function () use ($maintenanceStore, $invalidUtf8): void {
        $maintenanceStore->write(['fallback' => $invalidUtf8]);
    })
        ->toThrow(JsonException::class)
        ->and(File::exists($maintenanceStore->path()))->toBeFalse()
        ->and(function () use ($staticStore, $invalidUtf8): void {
            $staticStore->writeManifest(['invalid' => $invalidUtf8]);
        })
        ->toThrow(JsonException::class)
        ->and(File::exists($staticStore->manifestPath()))->toBeFalse();
});

it('returns the static manifest defaults for invalid JSON objects', function (): void {
    $store = new StaticPageArtifactStore;
    File::ensureDirectoryExists($store->root());

    File::put($store->manifestPath(), '["unexpected-list"]');

    expect($store->readManifest())->toBe(['generated_at' => null, 'artifacts' => []]);

    File::put($store->manifestPath(), '{invalid');

    expect($store->readManifest())->toBe(['generated_at' => null, 'artifacts' => []]);
});
