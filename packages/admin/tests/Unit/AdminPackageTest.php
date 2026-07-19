<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;

it('admin package does not have frontend scope', function (): void {
    $package = CapellCore::getPackage(AdminServiceProvider::$packageName);

    expect($package->hasFrontendScope())->toBeFalse();
});

it('keeps the frontend package optional', function (): void {
    $composerJson = file_get_contents(__DIR__ . '/../../composer.json');

    $composer = json_decode(
        $composerJson !== false ? $composerJson : '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'] ?? [])->not->toHaveKey('capell-app/frontend');
});

it('requires the Spatie media library Filament plugin', function (): void {
    $composerJson = file_get_contents(__DIR__ . '/../../composer.json');

    $composer = json_decode(
        $composerJson !== false ? $composerJson : '',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'] ?? [])
        ->toHaveKey('filament/spatie-laravel-media-library-plugin');
});

it('registers the optional default page creation action bridge', function (): void {
    expect(app()->bound('capell.admin.create-default-pages-action'))->toBeTrue();
    $action = resolve('capell.admin.create-default-pages-action');

    expect(is_callable($action))->toBeTrue();
});

it('declares the admin setup lifecycle command in the Capell manifest', function (): void {
    $manifestContents = file_get_contents(__DIR__ . '/../../capell.json');

    $manifest = json_decode(
        $manifestContents !== false ? $manifestContents : '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $setupParams = [
        'url',
        'user',
        'languages',
        'sites',
        'assets',
        'theme',
        'skip-panel-integration',
        'panel',
        'configurators',
        'no-colors',
        'no-widgets',
        'no-navigation',
        'skip-permission-sync',
        'force',
    ];

    expect($manifest['commands']['setup'] ?? null)
        ->toBe('capell:admin-setup')
        ->and($manifest['commands']['setupParams'] ?? [])
        ->toBe($setupParams)
        ->and(CapellCore::getPackage(AdminServiceProvider::$packageName)->getSetupCommand())
        ->toBe('capell:admin-setup')
        ->and(CapellCore::getPackage(AdminServiceProvider::$packageName)->getSetupParams())
        ->toBe($setupParams);
});
