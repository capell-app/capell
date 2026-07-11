<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\VerifyFrontendBuildAssetsAction;
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(public_path('vendor/capell-test-runtime'));
});

it('passes when a registered build manifest and hashed asset exist', function (): void {
    File::ensureDirectoryExists(public_path('vendor/capell-test-runtime/assets'));
    File::put(public_path('vendor/capell-test-runtime/manifest.json'), json_encode([
        'resources/js/runtime.js' => [
            'file' => 'assets/runtime-abc123.js',
        ],
    ], JSON_THROW_ON_ERROR));
    File::put(public_path('vendor/capell-test-runtime/assets/runtime-abc123.js'), 'console.log("ok")');

    CapellCore::registerVendorAsset(VendorAssetData::buildAsset(
        path: 'vendor/capell-test-runtime',
        file: 'resources/js/runtime.js',
        packageName: 'vendor/test-runtime',
    ));

    $result = expectPresent(VerifyFrontendBuildAssetsAction::run()->last());

    expect($result->passed)->toBeTrue()
        ->and($result->compiledFilePath)->toBe(public_path('vendor/capell-test-runtime/assets/runtime-abc123.js'));
});

it('fails when a registered build manifest is missing', function (): void {
    CapellCore::registerVendorAsset(VendorAssetData::buildAsset(
        path: 'vendor/capell-test-runtime',
        file: 'resources/js/runtime.js',
        packageName: 'vendor/test-runtime',
    ));

    $result = expectPresent(VerifyFrontendBuildAssetsAction::run()->last());

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('Missing build manifest')
        ->and($result->remediation)->not->toBeNull();
});

it('fails when a manifest entry points at a missing hashed asset', function (): void {
    File::ensureDirectoryExists(public_path('vendor/capell-test-runtime'));
    File::put(public_path('vendor/capell-test-runtime/manifest.json'), json_encode([
        'resources/js/runtime.js' => [
            'file' => 'assets/runtime-missing.js',
        ],
    ], JSON_THROW_ON_ERROR));

    CapellCore::registerVendorAsset(VendorAssetData::buildAsset(
        path: 'vendor/capell-test-runtime',
        file: 'resources/js/runtime.js',
        packageName: 'vendor/test-runtime',
    ));

    $result = expectPresent(VerifyFrontendBuildAssetsAction::run()->last());

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('Compiled asset is missing');
});

it('fails when a registered build asset omits its manifest source file', function (): void {
    CapellCore::registerVendorAsset(VendorAssetData::buildAsset(
        path: 'vendor/capell-test-runtime',
        file: '',
        packageName: 'vendor/test-runtime',
    ));

    $result = expectPresent(VerifyFrontendBuildAssetsAction::run()->last());

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toBe('Registered build asset does not declare a manifest source file.')
        ->and($result->remediation)->toContain('VendorAssetData::buildAsset');
});

it('fails when the manifest json is invalid or does not contain the source entry', function (): void {
    File::ensureDirectoryExists(public_path('vendor/capell-test-runtime'));
    File::put(public_path('vendor/capell-test-runtime/manifest.json'), 'not-json');

    CapellCore::registerVendorAsset(VendorAssetData::buildAsset(
        path: 'vendor/capell-test-runtime',
        file: 'resources/js/runtime.js',
        packageName: 'vendor/test-runtime',
    ));

    $invalidJsonResult = expectPresent(VerifyFrontendBuildAssetsAction::run()->last());

    expect($invalidJsonResult->passed)->toBeFalse()
        ->and($invalidJsonResult->message)->toContain('Build manifest is not valid JSON');

    File::put(public_path('vendor/capell-test-runtime/manifest.json'), json_encode([
        'resources/js/other.js' => ['file' => 'assets/other.js'],
    ], JSON_THROW_ON_ERROR));

    $missingEntryResult = expectPresent(VerifyFrontendBuildAssetsAction::run()->last());

    expect($missingEntryResult->passed)->toBeFalse()
        ->and($missingEntryResult->message)->toBe('Build manifest does not contain a file entry for resources/js/runtime.js.');
});
