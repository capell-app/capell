<?php

declare(strict_types=1);

use Capell\Core\Actions\Packages\BuildExtensionCapabilityReportAction;
use Capell\Core\Enums\ExtensionSurface;
use Capell\Core\Support\Manifest\CapellManifestData;

it('builds install impact reports from manifest and capability graph data', function (): void {
    $report = BuildExtensionCapabilityReportAction::run([
        'vendor/forms' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/forms',
            surfaces: ['frontend', 'admin'],
            overrides: [
                'product' => [
                    'group' => 'Growth',
                    'tier' => 'premium',
                    'bundle' => 'forms',
                ],
                'dependencies' => [
                    'requires' => ['capell-app/frontend'],
                    'supports' => ['capell-app/html-cache'],
                    'conflicts' => [],
                ],
                'contributes' => [
                    [
                        'type' => 'frontend-component',
                        'class' => 'Vendor\\Forms\\Components\\LeadForm',
                        'surface' => 'frontend',
                    ],
                    [
                        'type' => 'render-hook',
                        'class' => 'Vendor\\Forms\\Hooks\\TrackingHook',
                        'surface' => 'frontend',
                    ],
                ],
                'database' => [
                    'migrations' => true,
                    'settings' => true,
                    'requiredTables' => ['form_submissions'],
                ],
                'commands' => [
                    'install' => 'forms:install',
                    'setup' => null,
                    'demo' => null,
                ],
                'settings' => ['forms'],
                'permissions' => ['forms.manage'],
                'capabilities' => ['public-form', 'legacy-runtime'],
                'performance' => [
                    'cacheSafety' => [
                        'cacheable' => false,
                        'sensitiveOutput' => false,
                    ],
                ],
                'healthChecks' => [
                    [
                        'key' => 'forms-table',
                        'label' => 'Forms table',
                        'class' => 'Vendor\\Forms\\Health\\FormsTableHealth',
                    ],
                ],
                'marketplace' => [
                    'summary' => 'Capture public leads.',
                    'screenshots' => [
                        [
                            'path' => 'docs/assets/marketplace/form-builder.png',
                            'alt' => 'Lead form builder',
                            'caption' => 'Build public lead forms.',
                        ],
                    ],
                    'categories' => ['growth'],
                ],
            ],
        )),
        'vendor/search' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/search',
            surfaces: ['admin', 'operations'],
            overrides: [
                'capabilities' => ['search-admin'],
                'performance' => [
                    'cacheSafety' => [
                        'cacheable' => true,
                        'sensitiveOutput' => false,
                    ],
                ],
            ],
        )),
    ]);

    expect($report->surfaces)->toBe(['frontend', 'admin', 'operations'])
        ->and($report->packages)->toHaveCount(2)
        ->and($report->packages[0]->packageName)->toBe('vendor/forms')
        ->and($report->packages[0]->productGroup)->toBe('Growth')
        ->and($report->packages[0]->surfaces)->toBe(['frontend', 'admin'])
        ->and($report->packages[0]->surfaceDetails[0]->surface)->toBe(ExtensionSurface::Frontend)
        ->and($report->packages[0]->requiredPackages)->toBe(['capell-app/frontend'])
        ->and($report->packages[0]->supportingPackages)->toBe(['capell-app/html-cache'])
        ->and($report->packages[0]->contributionTypes)->toBe(['frontend-component', 'render-hook'])
        ->and($report->packages[0]->migrationImpact)->toBe(['migrations', 'settings-migrations', 'required-table:form_submissions'])
        ->and($report->packages[0]->commandImpact)->toBe(['install'])
        ->and($report->packages[0]->settings)->toBe(['forms'])
        ->and($report->packages[0]->permissions)->toBe(['forms.manage'])
        ->and($report->packages[0]->capabilities)->toContain('public-form', 'requires-livewire', 'render-hook', 'cache-blocking')
        ->and($report->packages[0]->unknownCapabilities)->toBe(['legacy-runtime'])
        ->and($report->packages[0]->cacheImpact)->toContain('cache-blocking')
        ->and($report->packages[0]->publicOutputImpact)->toBe('renders public frontend output')
        ->and($report->packages[0]->healthChecks)->toBe(['forms-table'])
        ->and($report->packages[0]->screenshots)->toBe(['docs/assets/marketplace/form-builder.png'])
        ->and($report->packages[0]->warnings)->toContain('vendor/forms declares unknown capability [legacy-runtime].')
        ->and($report->warnings)->toContain('vendor/forms declares unknown capability [legacy-runtime].')
        ->and($report->packages[1]->publicOutputImpact)->toBe('no public output declared');
});

it('preserves unknown surfaces for diagnostics without breaking reports', function (): void {
    $report = BuildExtensionCapabilityReportAction::run([
        'vendor/custom' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/custom',
            surfaces: ['admin', 'console', 'shared', 'bespoke'],
        )),
    ]);

    expect($report->surfaces)->toBe(['admin', 'console', 'shared', 'bespoke'])
        ->and($report->packages[0]->surfaceDetails[0]->label)->toBe('Admin')
        ->and($report->packages[0]->surfaceDetails[1]->label)->toBe('Console')
        ->and($report->packages[0]->surfaceDetails[2]->label)->toBe('Shared')
        ->and($report->packages[0]->surfaceDetails[3]->surface)->toBeNull()
        ->and($report->packages[0]->surfaceDetails[3]->value)->toBe('bespoke')
        ->and($report->packages[0]->surfaceDetails[3]->label)->toBe('Unknown surface');
});

it('reports content widget contributions as public output', function (): void {
    $report = BuildExtensionCapabilityReportAction::run([
        'vendor/content-widget' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/content-widget',
            surfaces: ['admin'],
            overrides: [
                'contributes' => [[
                    'type' => 'content-widget',
                    'class' => 'Vendor\\ContentWidget\\Widgets\\HeroWidget',
                ]],
                'performance' => [
                    'cacheSafety' => [
                        'cacheable' => true,
                        'sensitiveOutput' => false,
                    ],
                ],
            ],
        )),
    ]);

    expect($report->packages[0]->contributionTypes)->toBe(['content-widget'])
        ->and($report->packages[0]->publicOutputImpact)->toBe('renders public frontend output')
        ->and($report->packages[0]->cacheImpact)->toContain('public-static');
});
