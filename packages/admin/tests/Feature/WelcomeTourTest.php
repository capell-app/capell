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

    expect(array_map(fn (WelcomeTourStepData $step): string => $step->key, CapellAdmin::getWelcomeTourSteps()))
        ->toBe(['package.first', 'package.second']);
});

it('lets package admin bridges register welcome tour steps', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->welcomeTourStep(
        key: 'example-package.introduction',
        title: 'Package feature',
        description: 'Package feature tour step',
        element: '.package-feature',
        sort: 15,
    );

    $steps = CapellAdmin::getWelcomeTourSteps();

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->key)->toBe('example-package.introduction')
        ->and($steps[0]->element)->toBe('.package-feature');
});
