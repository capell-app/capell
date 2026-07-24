<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Events\FrontendContextResolved;
use Capell\Frontend\Listeners\OnFrontendContextResolved;
use Capell\Frontend\Support\Render\RenderHookRegistry;

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

it('does not attribute a declared render hook before it is rendered', function (): void {
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

    expect(resolve(RecordExtensionRenderContributionAction::class)->recorded())->toBe([]);
});

it('attributes page types and variations only to matching page models', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
        overrides: [
            'contributes' => [
                [
                    'type' => 'page-type',
                    'class' => 'Vendor\\EditorialTools\\Manifest\\PageType',
                    'modelClass' => Page::class,
                ],
                [
                    'type' => 'page-variation',
                    'class' => 'Vendor\\EditorialTools\\Manifest\\ArticleVariation',
                    'modelClass' => 'Vendor\\EditorialTools\\Models\\Article',
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
        page: new Page,
        layout: null,
        theme: null,
        params: [],
        slug: null,
    )));

    $records = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($records)->toHaveCount(1)
        ->and($records[0]->contributionType)->toBe('page-type')
        ->and($records[0]->contributionClass)->toBe('Vendor\\EditorialTools\\Manifest\\PageType');
});

it('attributes only selected rendered hooks with their explicit cache safety', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
        overrides: [
            'performance' => [
                'frontendRenderBudgetMs' => 0,
                'cacheTags' => ['extension:editorial-tools'],
                'cacheSafety' => [
                    'cacheable' => false,
                    'variesBy' => ['site', 'locale'],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [],
                    'queueInvalidation' => true,
                ],
            ],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
    ]);

    $safeHook = new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<aside>safe</aside>';
        }
    };
    $unsafeHook = new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<aside>unsafe</aside>';
        }
    };

    $registry = new RenderHookRegistry;
    $registry->contribute(new RenderHookContributionData(
        location: RenderHookLocation::Footer,
        extension: $safeHook,
        owner: $manifest->name,
        key: 'safe-footer',
        target: 'marketing',
        cacheSafe: true,
    ));
    $registry->contribute(new RenderHookContributionData(
        location: RenderHookLocation::Footer,
        extension: $unsafeHook,
        owner: $manifest->name,
        key: 'unsafe-footer',
        target: 'article',
        cacheSafe: false,
    ));

    expect($registry->renderAll(RenderHookLocation::Footer, target: 'marketing'))
        ->toBe('<aside>safe</aside>');

    $records = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($records)->toHaveCount(1)
        ->and($records[0]->packageName)->toBe($manifest->name)
        ->and($records[0]->contributionType)->toBe('render-hook')
        ->and($records[0]->contributionClass)->toBe($safeHook::class)
        ->and($records[0]->cacheTags)->toBe(['extension:editorial-tools'])
        ->and($records[0]->cacheable)->toBeTrue()
        ->and($records[0]->sensitiveOutput)->toBeFalse()
        ->and($records[0]->variesBy)->toBe(['site', 'locale']);

    resolve(RecordExtensionRenderContributionAction::class)->clear();

    expect($registry->renderAll(RenderHookLocation::Footer, target: 'article'))
        ->toBe('<aside>unsafe</aside>');

    $records = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($records)->toHaveCount(1)
        ->and($records[0]->contributionClass)->toBe($unsafeHook::class)
        ->and($records[0]->cacheable)->toBeFalse();
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
