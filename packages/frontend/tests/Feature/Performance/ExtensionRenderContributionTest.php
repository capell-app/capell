<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Events\FrontendContextResolved;
use Capell\Frontend\Listeners\OnFrontendContextResolved;

beforeEach(function (): void {
    resolve(CapellPackageRegistry::class)->fill([]);
    resolve(RecordExtensionRenderContributionAction::class)->clear();
});

it('records extension render contribution metadata in the current request', function (): void {
    $record = RecordExtensionRenderContributionAction::run(
        packageName: 'vendor/editorial-tools',
        surface: 'frontend',
        contributionType: 'frontend-component',
        contributionClass: 'Vendor\\EditorialTools\\Components\\RelatedStories',
        elapsedMilliseconds: 12.4,
        frontendRenderBudgetMs: 10,
        cacheTags: ['extension:editorial-tools', 'content:article'],
        cacheable: true,
        sensitiveOutput: false,
        variesBy: ['site', 'locale'],
    );

    $recorded = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($record->packageName)->toBe('vendor/editorial-tools')
        ->and($record->surface)->toBe('frontend')
        ->and($record->elapsedMilliseconds)->toBe(12.4)
        ->and($record->cacheTags)->toBe(['extension:editorial-tools', 'content:article'])
        ->and($record->budgetExceeded)->toBeTrue()
        ->and($recorded)->toHaveCount(1)
        ->and($recorded[0])->toBe($record);
});

it('records manifest frontend contributions when the frontend context is resolved', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
        overrides: [
            'performance' => [
                'frontendRenderBudgetMs' => 0,
                'cacheTags' => ['extension:editorial-tools', 'content:article'],
                'cacheSafety' => [
                    'cacheable' => true,
                    'variesBy' => ['site', 'locale'],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [
                        ['model' => Page::class, 'events' => ['saved']],
                    ],
                    'queueInvalidation' => true,
                ],
            ],
            'contributes' => [
                [
                    'type' => 'frontend-component',
                    'class' => 'Vendor\\EditorialTools\\Components\\RelatedStories',
                    'surface' => 'frontend',
                ],
                [
                    'type' => 'render-hook',
                    'class' => 'Vendor\\EditorialTools\\Hooks\\ArticleMeta',
                    'surface' => 'frontend',
                ],
            ],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
    ]);

    resolve(OnFrontendContextResolved::class)->handle(new FrontendContextResolved(new FrontendContext(
        site: null,
        language: null,
        page: null,
        layout: null,
        theme: null,
        params: [],
        slug: null,
    )));

    $records = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($records)->toHaveCount(1)
        ->and($records[0]->packageName)->toBe('vendor/editorial-tools')
        ->and($records[0]->surface)->toBe('frontend')
        ->and($records[0]->contributionType)->toBe('render-hook')
        ->and($records[0]->contributionClass)->toBe('Vendor\\EditorialTools\\Hooks\\ArticleMeta')
        ->and($records[0]->cacheTags)->toBe(['extension:editorial-tools', 'content:article'])
        ->and($records[0]->cacheable)->toBeTrue()
        ->and($records[0]->sensitiveOutput)->toBeFalse()
        ->and($records[0]->variesBy)->toBe(['site', 'locale'])
        ->and($records[0]->budgetExceeded)->toBeFalse();
});

it('does not record dashboard Filament widgets as public frontend render contributions', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/search-tools',
        surfaces: ['admin', 'frontend'],
        overrides: [
            'performance' => [
                'frontendRenderBudgetMs' => 0,
                'cacheTags' => ['search'],
                'cacheSafety' => [
                    'cacheable' => false,
                    'variesBy' => ['site', 'locale'],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [],
                    'queueInvalidation' => true,
                ],
            ],
            'contributes' => [
                [
                    'type' => 'dashboard-widget',
                    'class' => 'Vendor\\SearchTools\\Widgets\\TopSearches',
                    'widgetClass' => 'Vendor\\SearchTools\\Widgets\\TopSearches',
                ],
            ],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
    ]);

    resolve(OnFrontendContextResolved::class)->handle(new FrontendContextResolved(new FrontendContext(
        site: null,
        language: null,
        page: null,
        layout: null,
        theme: null,
        params: [],
        slug: null,
    )));

    expect(resolve(RecordExtensionRenderContributionAction::class)->recorded())->toBe([]);
});
