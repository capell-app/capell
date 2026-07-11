<?php

declare(strict_types=1);

use Capell\Core\Contracts\DraftableContract;
use Capell\Core\Contracts\SettingsOwnerContract;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Frontend\Enums\RenderHookLocation;

it('every installed Capell package with a capell.json has a valid manifest', function (): void {
    $validator = new ManifestValidator;
    $loader = new ManifestLoader($validator);
    $manifests = $loader->discover();

    expect($manifests)->not->toBeEmpty('No capell.json manifests were discovered');

    foreach ($manifests as $packageName => $manifest) {
        expect($manifest->name)->toBe($packageName, sprintf("Package %s: manifest 'name' must match composer package name", $packageName));
        expect($manifest->kind)->toBeIn(['package', 'plugin', 'theme', 'integration', 'bundle'], sprintf("Package %s: invalid kind '%s'", $packageName, $manifest->kind));
        expect($manifest->capellApiVersion)->not->toBeEmpty(sprintf('Package %s: capellApiVersion must not be empty', $packageName));
    }
})->group('Core');

it('all platform manifests use manifest v3 only', function (): void {
    $repositoryRoot = dirname(__DIR__, 4);

    $paths = collect(glob($repositoryRoot . '/packages/*/capell.json'));

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest['manifest-version'] ?? null)->toBe(3, $path)
            ->and($manifest)->toHaveKey('surfaces')
            ->and($manifest)->toHaveKey('dependencies')
            ->and($manifest)->toHaveKey('capellApiVersion')
            ->and($manifest)->not->toHaveKey('contexts')
            ->and($manifest)->not->toHaveKey('requires')
            ->and($manifest)->not->toHaveKey('capell-version')
            ->and($manifest)->not->toHaveKey('optional')
            ->and($manifest['providers'] ?? [])->not->toHaveKey('shared')
            ->and($manifest['providers'] ?? [])->not->toHaveKey('console');
    }
})->group('Core');

it('every provider declared in a capell.json manifest exists as a class', function (): void {
    $loader = new ManifestLoader(new ManifestValidator);
    $manifests = $loader->discover();

    expect($manifests)->not->toBeEmpty('No capell.json manifests were discovered');

    foreach ($manifests as $packageName => $manifest) {
        foreach ($manifest->providers->toArray() as $context => $providers) {
            foreach ($providers as $provider) {
                expect(class_exists($provider))->toBeTrue(
                    sprintf("Package %s: provider '%s' declared for context '%s' does not exist", $packageName, $provider, $context),
                );
            }
        }
    }
})->group('Core');

it('every RenderHookLocation case has a non-empty string value', function (): void {
    foreach (RenderHookLocation::cases() as $case) {
        expect($case->value)->not->toBeEmpty(sprintf('RenderHookLocation::%s has an empty value', $case->name));
        expect($case->value)->toBeString();
    }
})
    ->group('Core');

it('RenderHookLocation includes every required hook case', function (): void {
    $cases = collect(RenderHookLocation::cases())
        ->mapWithKeys(fn (RenderHookLocation $case): array => [$case->name => $case->value])
        ->all();

    expect($cases)->toMatchArray([
        'BeforeTitle' => 'beforeTitle',
        'AfterTitle' => 'afterTitle',
        'Footer' => 'footer',
        'BeforeResult' => 'beforeResult',
        'AfterResult' => 'afterResult',
        'ArticleMeta' => 'articleMeta',
        'BeforeContent' => 'beforeContent',
        'AfterContent' => 'afterContent',
        'MainContent' => 'mainContent',
        'HeadOpen' => 'headOpen',
        'HeadClose' => 'headClose',
        'BodyStart' => 'bodyStart',
        'HeaderBefore' => 'headerBefore',
        'HeaderAfter' => 'headerAfter',
        'FooterBefore' => 'footerBefore',
        'FooterAfter' => 'footerAfter',
        'BodyEnd' => 'bodyEnd',
    ]);
})
    ->group('Core');

it('DraftableContract exists in the core contracts namespace', function (): void {
    expect(interface_exists(DraftableContract::class))->toBeTrue();
})->group('Core');

it('SettingsOwnerContract exists in the core contracts namespace', function (): void {
    expect(interface_exists(SettingsOwnerContract::class))->toBeTrue();
})->group('Core');
