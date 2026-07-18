<?php

declare(strict_types=1);

use Capell\Admin\Data\WelcomeTourStepData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;

beforeEach(function (): void {
    CapellAdmin::clearWelcomeTourSteps();
});

it('registers visible welcome tour steps in sort order', function (): void {
    CapellAdmin::registerWelcomeTourStep(
        key: 'package.second',
        title: 'Second',
        description: 'Second step',
        element: '.second',
        sort: 20,
        chapter: 'pages',
        route: '/admin/pages',
    );

    CapellAdmin::registerWelcomeTourStep(
        key: 'package.hidden',
        title: 'Hidden',
        description: 'Hidden step',
        sort: 5,
        visible: false,
    );

    CapellAdmin::registerWelcomeTourStep(
        key: 'package.first',
        title: 'First',
        description: 'First step',
        element: '.first',
        sort: 10,
    );

    $steps = CapellAdmin::getWelcomeTourSteps();

    expect(array_map(fn (WelcomeTourStepData $step): string => $step->key, $steps))
        ->toBe(['package.first', 'package.second'])
        ->and($steps[0]->chapter)->toBe('dashboard')
        ->and($steps[0]->route)->toBeNull()
        ->and($steps[1]->chapter)->toBe('pages')
        ->and($steps[1]->route)->toBe('/admin/pages');
});

it('lets package admin bridges register welcome tour steps', function (): void {
    $registrar = resolve(AdminBridgeRegistrar::class);

    $registrar->welcomeTourStep(
        key: 'example-package.introduction',
        title: 'Package feature',
        description: 'Package feature tour step',
        element: '.package-feature',
        sort: 15,
        chapter: 'sites',
        route: '/admin/sites',
    );

    $steps = CapellAdmin::getWelcomeTourSteps();

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->key)->toBe('example-package.introduction')
        ->and($steps[0]->element)->toBe('.package-feature')
        ->and($steps[0]->chapter)->toBe('sites')
        ->and($steps[0]->route)->toBe('/admin/sites');
});
