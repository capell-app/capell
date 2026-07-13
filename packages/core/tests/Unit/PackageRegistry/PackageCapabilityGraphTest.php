<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Actions\Packages\BuildPackageCapabilityGraphAction;
use Capell\Core\Contracts\Extensions\RegistersExtensionAsset;
use Capell\Core\Contracts\Extensions\RegistersExtensionFrontendComponent;
use Capell\Core\Contracts\Extensions\RegistersExtensionRenderHook;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Models\Page;
use Capell\Core\Support\Manifest\CapellManifestData;

beforeAll(function (): void {
    if (class_exists('Vendor\\Capability\\Components\\LeadForm')) {
        return;
    }

    eval('
        namespace Vendor\\Capability\\Components {
            class LeadForm implements \\' . RegistersExtensionFrontendComponent::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Capability\\Hooks {
            class TrackingHook implements \\' . RegistersExtensionRenderHook::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Capability\\Assets {
            class ThemeAssets implements \\' . RegistersExtensionAsset::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }
    ');
});

it('builds typed package capability nodes from explicit and derived manifest data', function (): void {
    $graph = BuildPackageCapabilityGraphAction::run([
        'vendor/forms' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/forms',
            surfaces: ['frontend'],
            overrides: [
                'capabilities' => ['public-form', 'legacy-form-runtime'],
                'contributes' => [
                    [
                        'type' => 'frontend-component',
                        'class' => 'Vendor\\Capability\\Components\\LeadForm',
                        'surface' => 'frontend',
                    ],
                    [
                        'type' => 'render-hook',
                        'class' => 'Vendor\\Capability\\Hooks\\TrackingHook',
                        'surface' => 'frontend',
                    ],
                ],
                'performance' => [
                    'cacheSafety' => [
                        'cacheable' => false,
                        'sensitiveOutput' => false,
                    ],
                ],
            ],
        )),
        'vendor/theme' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/theme',
            surfaces: ['frontend'],
            overrides: [
                'capabilities' => ['public-static'],
                'contributes' => [
                    [
                        'type' => 'asset',
                        'class' => 'Vendor\\Capability\\Assets\\ThemeAssets',
                        'surface' => 'frontend',
                    ],
                ],
                'performance' => [
                    'cacheSafety' => [
                        'cacheable' => true,
                        'invalidationSources' => [
                            ['model' => Page::class, 'events' => ['saved']],
                        ],
                    ],
                ],
            ],
        )),
    ]);

    expect($graph->packageHas('vendor/forms', PackageCapability::PublicForm))->toBeTrue()
        ->and($graph->packageHas('vendor/forms', PackageCapability::PublicStatic))->toBeFalse()
        ->and($graph->packageHas('vendor/forms', PackageCapability::RequiresLivewire))->toBeTrue()
        ->and($graph->packageHas('vendor/forms', PackageCapability::RenderHook))->toBeTrue()
        ->and($graph->packageHas('vendor/forms', PackageCapability::CacheBlocking))->toBeTrue()
        ->and($graph->capabilitiesFor('vendor/forms'))->toContain(
            PackageCapability::PublicForm,
            PackageCapability::RequiresLivewire,
            PackageCapability::RenderHook,
            PackageCapability::CacheBlocking,
        )
        ->and($graph->unknownFor('vendor/forms'))->toBe(['legacy-form-runtime'])
        ->and($graph->packageHas('vendor/theme', PackageCapability::PublicStatic))->toBeTrue()
        ->and($graph->packageHas('vendor/theme', PackageCapability::FrontendAssets))->toBeTrue()
        ->and($graph->packagesWith(PackageCapability::PublicStatic))->toBe(['vendor/theme'])
        ->and($graph->toArray()['unknownCapabilities'])->toBe([
            'vendor/forms' => ['legacy-form-runtime'],
        ]);
});

it('derives frontend runtime and cache capabilities from performance metadata', function (): void {
    $cacheSafePerformance = [
        'cacheSafety' => [
            'cacheable' => true,
            'sensitiveOutput' => false,
        ],
    ];

    $graph = BuildPackageCapabilityGraphAction::run([
        'vendor/livewire-runtime' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/livewire-runtime',
            surfaces: ['frontend'],
            overrides: [
                'performance' => [
                    ...$cacheSafePerformance,
                    'requiresLivewire' => true,
                ],
            ],
        )),
        'vendor/uncacheable' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/uncacheable',
            surfaces: ['frontend'],
            overrides: [
                'performance' => [
                    ...$cacheSafePerformance,
                    'cacheabilityProfile' => 'uncacheable',
                ],
            ],
        )),
        'vendor/public-query-risk' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/public-query-risk',
            surfaces: ['frontend'],
            overrides: [
                'performance' => [
                    ...$cacheSafePerformance,
                    'publicQueryRisk' => true,
                ],
            ],
        )),
        'vendor/static-theme' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/static-theme',
            surfaces: ['frontend'],
            overrides: [
                'performance' => $cacheSafePerformance,
            ],
        )),
    ]);

    expect($graph->packageHas('vendor/livewire-runtime', PackageCapability::RequiresLivewire))->toBeTrue()
        ->and($graph->packageHas('vendor/livewire-runtime', PackageCapability::CacheBlocking))->toBeFalse()
        ->and($graph->packageHas('vendor/uncacheable', PackageCapability::CacheBlocking))->toBeTrue()
        ->and($graph->packageHas('vendor/public-query-risk', PackageCapability::CacheBlocking))->toBeTrue()
        ->and($graph->packageHas('vendor/static-theme', PackageCapability::PublicStatic))->toBeTrue()
        ->and($graph->packageHas('vendor/static-theme', PackageCapability::CacheBlocking))->toBeFalse();
});

it('classifies content widgets as public rendering contributions without redundant surface metadata', function (): void {
    $graph = BuildPackageCapabilityGraphAction::run([
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

    expect($graph->packageHas('vendor/content-widget', PackageCapability::PublicStatic))->toBeTrue()
        ->and($graph->packageHas('vendor/content-widget', PackageCapability::CacheBlocking))->toBeFalse();
});

it('audits unknown capabilities and missing typed frontend capability declarations as warnings', function (): void {
    $directory = sys_get_temp_dir() . '/capell-capability-audit-' . bin2hex(random_bytes(6));
    mkdir($directory, recursive: true);

    file_put_contents($directory . '/composer.json', json_encode([
        'name' => 'vendor/capability-audit',
        'autoload' => [
            'psr-4' => [
                'Vendor\\Capability\\' => 'src/',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($directory . '/capell.json', json_encode(capellManifestV3Array(
        name: 'vendor/capability-audit',
        surfaces: ['frontend'],
        overrides: [
            'capabilities' => ['legacy-runtime'],
            'contributes' => [
                [
                    'type' => 'frontend-component',
                    'class' => 'Vendor\\Capability\\Components\\LeadForm',
                    'surface' => 'frontend',
                ],
            ],
        ],
    ), JSON_THROW_ON_ERROR));

    $messages = collect(AuditExtensionContractsAction::run($directory))->pluck('message')->all();

    expect($messages)->toContain('Manifest declares capability strings outside the typed package capability graph.')
        ->and($messages)->toContain('Frontend package contribution is missing typed package capabilities.');
});
