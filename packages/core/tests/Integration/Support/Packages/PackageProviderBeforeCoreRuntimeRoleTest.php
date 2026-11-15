<?php

declare(strict_types=1);

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Core\Tests\Support\RuntimeLifecycleFixture;

it('registers a package provider that is discovered before the Core provider has bound the runtime role', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: RuntimeLifecycleFixture::$packageName,
        providers: ['runtime' => [RuntimeLifecycleFixture::class]],
    ));
    app()->singletonIf(CapellPackageRegistry::class);
    resolve(CapellPackageRegistry::class)->register($manifest);

    app()->offsetUnset(RuntimeRoleResolver::class);
    app()->offsetUnset(RuntimeRoleSelectionData::class);

    app()->register(RuntimeLifecycleFixture::class, force: true);

    expect(resolve(RuntimeRoleResolver::class)->role())->toBe(RuntimeRoleResolver::fromEnvironment()->role())
        ->and(resolve(RuntimeRoleSelectionData::class))->toBe(resolve(RuntimeRoleResolver::class)->selection())
        ->and(RuntimeRole::cases())->toContain(resolve(RuntimeRoleResolver::class)->role());
});
