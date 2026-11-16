<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\PackageWorkflowPlanner;

/**
 * @param  list<string>  $requirements
 * @param  list<string>  $supportingPackages
 */
function registerWorkflowPackage(
    string $packageName,
    array $requirements = [],
    int $sort = 0,
    array $supportingPackages = [],
    string $visibility = 'catalogue',
    array $roles = [],
    ?int $rolePriority = null,
): void {
    CapellCore::registerPackage(name: $packageName);
    $package = CapellCore::getPackage($packageName);
    $package->declaredRoles = array_values($roles);
    $package->declaredRolePriority = $rolePriority;
    $package->requirements = $requirements;
    $package->sort = $sort;
    $package->supportingPackages = $supportingPackages;
    $package->visibility = $visibility;
}

it('expands selected packages with registered requirements before ordering main packages', function (): void {
    registerWorkflowPackage('capell-app/admin', sort: 10);
    registerWorkflowPackage('capell-app/tags', ['capell-app/admin'], 20);
    registerWorkflowPackage('capell-app/foundation-theme', ['capell-app/admin'], 30);
    registerWorkflowPackage('capell-app/blog', ['capell-app/foundation-theme', 'capell-app/tags'], 40);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/blog'],
    );

    expect($orderedPackages->keys()->all())->toBe([
        'capell-app/admin',
        'capell-app/tags',
        'capell-app/foundation-theme',
        'capell-app/blog',
    ]);
});

it('uses configured package order for packages at the same dependency level', function (): void {
    registerWorkflowPackage('capell-app/blog', sort: 30);
    registerWorkflowPackage('capell-app/form-builder', sort: 10);
    registerWorkflowPackage('capell-app/insights', sort: 20);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/blog', 'capell-app/form-builder', 'capell-app/insights'],
    );

    expect($orderedPackages->keys()->all())->toBe([
        'capell-app/form-builder',
        'capell-app/insights',
        'capell-app/blog',
    ]);
});

it('excludes composer-only core packages from selected workflow packages', function (): void {
    registerWorkflowPackage('capell-app/core', sort: 0);
    registerWorkflowPackage('capell-app/capell', sort: 5);
    registerWorkflowPackage('capell-app/admin', sort: 10);
    registerWorkflowPackage('capell-app/frontend', sort: 20);
    registerWorkflowPackage('capell-app/installer', sort: 30);
    registerWorkflowPackage('capell-app/marketplace', sort: 40);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        [
            'capell-app/core',
            'capell-app/capell',
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/installer',
            'capell-app/marketplace',
        ],
    );

    expect($orderedPackages->keys()->all())->toBe([
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/installer',
        'capell-app/marketplace',
    ]);
});

it('includes foundation package lifecycles when they are required by a selected package', function (): void {
    registerWorkflowPackage('capell-app/admin', sort: 10);
    registerWorkflowPackage('vendor/admin-addon', ['capell-app/admin'], 20);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['vendor/admin-addon'],
    );

    expect($orderedPackages->keys()->all())->toBe([
        'capell-app/admin',
        'vendor/admin-addon',
    ]);
});

it('can include installed package requirements for fresh installs', function (): void {
    registerWorkflowPackage('capell-app/content-sections', sort: 10);
    registerWorkflowPackage('capell-app/demo-kit', ['capell-app/content-sections'], 20);
    CapellCore::forcePackageInstalled('capell-app/content-sections');

    $regularInstallPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/demo-kit'],
    );

    $freshInstallPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/demo-kit'],
        includeInstalledRequirements: true,
    );

    expect($regularInstallPackages->keys()->all())->toBe(['capell-app/demo-kit'])
        ->and($freshInstallPackages->keys()->all())->toBe([
            'capell-app/content-sections',
            'capell-app/demo-kit',
        ]);
});

it('keeps packages that declare the workflow-final role last', function (): void {
    registerWorkflowPackage('vendor/workflow', sort: 1, roles: [PackageRole::WorkflowFinal]);
    registerWorkflowPackage('capell-app/blog', sort: 30);
    registerWorkflowPackage('capell-app/form-builder', sort: 10);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['vendor/workflow', 'capell-app/blog', 'capell-app/form-builder'],
    );

    expect($orderedPackages->keys()->all())->toBe([
        'capell-app/form-builder',
        'capell-app/blog',
        'vendor/workflow',
    ]);
});

it('orders several workflow-final packages by role priority and then name', function (): void {
    registerWorkflowPackage('vendor/late', sort: 1, roles: [PackageRole::WorkflowFinal], rolePriority: 5);
    registerWorkflowPackage('vendor/early', sort: 99, roles: [PackageRole::WorkflowFinal], rolePriority: 1);
    registerWorkflowPackage('vendor/plain', sort: 50);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['vendor/late', 'vendor/plain', 'vendor/early'],
    );

    expect($orderedPackages->keys()->all())->toBe(['vendor/plain', 'vendor/early', 'vendor/late']);
});

it('does not give a package workflow-final treatment because of its name', function (): void {
    registerWorkflowPackage('capell-app/publishing-studio', sort: 1);
    registerWorkflowPackage('capell-app/blog', sort: 30);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/publishing-studio', 'capell-app/blog'],
    );

    expect($orderedPackages->keys()->all())->toBe(['capell-app/publishing-studio', 'capell-app/blog']);
});

it('installs workflow packages before packages that require them', function (): void {
    registerWorkflowPackage('capell-app/admin', sort: 10);
    registerWorkflowPackage('capell-app/frontend', sort: 20);
    registerWorkflowPackage('capell-app/publishing-studio', ['capell-app/admin'], 100, roles: [PackageRole::WorkflowFinal]);
    registerWorkflowPackage('capell-app/foundation-theme', [
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/publishing-studio',
    ], 30);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/foundation-theme'],
    );

    expect($orderedPackages->keys()->all())->toBe([
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/publishing-studio',
        'capell-app/foundation-theme',
    ]);
});

it('expands support packages when their requirements are already selected', function (): void {
    registerWorkflowPackage('vendor/admin-panel', sort: 10);
    registerWorkflowPackage('capell-app/foundation-theme', sort: 20, visibility: 'support');
    registerWorkflowPackage('capell-app/theme-agency', ['vendor/admin-panel'], 30, visibility: 'support');
    registerWorkflowPackage(
        'capell-app/theme-saas',
        sort: 40,
        supportingPackages: ['capell-app/foundation-theme', 'capell-app/theme-agency'],
    );

    $frontendOnlyPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/theme-saas'],
    );

    $adminPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['vendor/admin-panel', 'capell-app/theme-saas'],
    );

    expect($frontendOnlyPackages->keys()->all())->toBe([
        'capell-app/foundation-theme',
        'capell-app/theme-saas',
    ])->and($adminPackages->keys()->all())->toBe([
        'vendor/admin-panel',
        'capell-app/foundation-theme',
        'capell-app/theme-agency',
        'capell-app/theme-saas',
    ]);
});

it('expands support packages when their requirements are already installed', function (): void {
    registerWorkflowPackage('vendor/admin-panel', sort: 10);
    CapellCore::forcePackageInstalled('vendor/admin-panel');

    registerWorkflowPackage('capell-app/theme-agency', ['vendor/admin-panel'], 20, visibility: 'support');
    registerWorkflowPackage(
        'capell-app/theme-saas',
        sort: 30,
        supportingPackages: ['capell-app/theme-agency'],
    );

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/theme-saas'],
    );

    expect($orderedPackages->keys()->all())->toBe([
        'capell-app/theme-agency',
        'capell-app/theme-saas',
    ]);
});

it('reports circular requirements instead of recursing during package expansion', function (): void {
    registerWorkflowPackage('capell-app/package-a', ['capell-app/package-b'], 10);
    registerWorkflowPackage('capell-app/package-b', ['capell-app/package-a'], 20);

    expect(fn () => resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['capell-app/package-a'],
    ))->toThrow(Exception::class, 'Circular dependency detected in packages');
});

it('orders workflow-final packages by their exact priority, including negative and very large values', function (): void {
    registerWorkflowPackage('vendor/negative', sort: 99, roles: [PackageRole::WorkflowFinal], rolePriority: -1);
    registerWorkflowPackage('vendor/zero', sort: 1, roles: [PackageRole::WorkflowFinal], rolePriority: 0);
    registerWorkflowPackage('vendor/largest', sort: 1, roles: [PackageRole::WorkflowFinal], rolePriority: PHP_INT_MAX);
    registerWorkflowPackage('vendor/plain', sort: 50);

    $orderedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
        CapellCore::getPackages(),
        ['vendor/largest', 'vendor/zero', 'vendor/plain', 'vendor/negative'],
    );

    expect($orderedPackages->keys()->all())->toBe(['vendor/plain', 'vendor/negative', 'vendor/zero', 'vendor/largest']);
});
