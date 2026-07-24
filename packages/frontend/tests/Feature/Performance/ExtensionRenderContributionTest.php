<?php

declare(strict_types=1);

use Capell\Core\Data\RenderableContributionIdentityData;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Models\Page;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Frontend\Actions\CollectFrontendResourceContributionsAction;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Actions\RenderRenderableAction;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\ViteResourceSourceData;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Events\FrontendContextResolved;
use Capell\Frontend\Listeners\OnFrontendContextResolved;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

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

it('does not attribute declared sections or assets before they are selected', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
        overrides: [
            'contributes' => [
                [
                    'type' => 'section',
                    'class' => 'Vendor\\EditorialTools\\Sections\\ArticleHero',
                ],
                [
                    'type' => 'asset',
                    'class' => 'Vendor\\EditorialTools\\Assets\\ArticleAssets',
                ],
            ],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);

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

it('attributes only the selected asset contributor execution', function (): void {
    $contributor = new class implements FrontendResourceContributor
    {
        public function resources(FrontendResourceContextData $context): array
        {
            return [
                new FrontendResourceContributionData(FrontendResourceData::moduleScript(
                    handle: 'vendor/editorial-tools:article',
                    package: 'vendor/editorial-tools',
                    source: new ViteResourceSourceData('resources/js/article.js'),
                )),
            ];
        }
    };
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
        overrides: [
            'contributes' => [[
                'type' => 'asset',
                'class' => $contributor::class,
                'surface' => 'frontend',
            ]],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
    app()->instance('test.selected-asset-contributor', $contributor);
    app()->tag('test.selected-asset-contributor', FrontendResourceContributor::TAG);

    CollectFrontendResourceContributionsAction::run(new FrontendResourceContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    $records = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($records)->toHaveCount(1)
        ->and($records[0]->contributionType)->toBe('asset')
        ->and($records[0]->contributionClass)->toBe($contributor::class)
        ->and($records[0]->elapsedMilliseconds)->toBeGreaterThanOrEqual(0.0);
});

it('attributes a section only when its renderable is rendered', function (): void {
    $viewPath = storage_path('framework/testing/extension-section-views');
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/article.blade.php', '<section>Article</section>');
    View::addNamespace('extension-section-test', $viewPath);

    $asset = new class extends Model
    {
        use HasFactory;
    };
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
        overrides: [
            'contributes' => [[
                'type' => 'section',
                'class' => 'Vendor\\EditorialTools\\Sections\\Article',
                'modelClass' => $asset::class,
                'section' => 'article',
            ]],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'article',
        type: 'section',
        blade: 'extension-section-test::article',
        contribution: new RenderableContributionIdentityData(
            owner: $manifest->name,
            type: ExtensionContributionType::Section,
            class: 'Vendor\\EditorialTools\\Sections\\Article',
        ),
    ));

    expect(RenderRenderableAction::run(
        type: 'section',
        key: 'article',
        asset: $asset,
        translation: new class extends Model
        {
            use HasFactory;
        },
    ))->toBe('<section>Article</section>');

    $records = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($records)->toHaveCount(1)
        ->and($records[0]->contributionType)->toBe('section')
        ->and($records[0]->contributionClass)->toBe('Vendor\\EditorialTools\\Sections\\Article')
        ->and($records[0]->elapsedMilliseconds)->toBeGreaterThanOrEqual(0.0);
});

it('uses the selected renderable contribution identity without cross matching manifests', function (): void {
    $viewPath = storage_path('framework/testing/exact-extension-section-views');
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/article.blade.php', '<section>Exact article</section>');
    View::addNamespace('exact-extension-section-test', $viewPath);

    $selectedManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
    ));
    $unselectedManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/other-tools',
        surfaces: ['frontend'],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $selectedManifest->name => $selectedManifest,
        $unselectedManifest->name => $unselectedManifest,
    ]);
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'article',
        type: 'section',
        blade: 'exact-extension-section-test::article',
        contribution: new RenderableContributionIdentityData(
            owner: $selectedManifest->name,
            type: ExtensionContributionType::Section,
            class: 'Vendor\\EditorialTools\\Sections\\Article',
        ),
    ));

    RenderRenderableAction::run(
        type: 'section',
        key: 'article',
        asset: new class extends Model
        {
            use HasFactory;
        },
        translation: new class extends Model
        {
            use HasFactory;
        },
    );

    $records = resolve(RecordExtensionRenderContributionAction::class)->recorded();

    expect($records)->toHaveCount(1)
        ->and($records[0]->packageName)->toBe($selectedManifest->name)
        ->and($records[0]->contributionType)->toBe(ExtensionContributionType::Section->value);
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
        ->and($records[0]->contributionClass)->toBe('Vendor\\EditorialTools\\Manifest\\PageType')
        ->and($records[0]->elapsedMilliseconds)->toBe(0.0);
});

it('fails closed for page contributions without a concrete model identity', function (mixed $modelClass): void {
    $contribution = [
        'type' => 'page-type',
        'class' => 'Vendor\\EditorialTools\\Manifest\\PageType',
    ];

    if ($modelClass !== null) {
        $contribution['modelClass'] = $modelClass;
    }

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['frontend'],
        overrides: ['contributes' => [$contribution]],
    ));

    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
    resolve(OnFrontendContextResolved::class)->handle(new FrontendContextResolved(new FrontendContext(
        site: null,
        language: null,
        page: new Page,
        layout: null,
        theme: null,
        params: [],
        slug: null,
    )));

    expect(resolve(RecordExtensionRenderContributionAction::class)->recorded())->toBe([]);
})->with([
    'missing' => null,
    'empty' => '',
    'non string' => ['page'],
]);

it('fails closed when page contribution identity is ambiguous', function (): void {
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
                    'class' => 'Vendor\\EditorialTools\\Manifest\\PageVariation',
                    'modelClass' => Page::class,
                ],
            ],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
    resolve(OnFrontendContextResolved::class)->handle(new FrontendContextResolved(new FrontendContext(
        site: null,
        language: null,
        page: new Page,
        layout: null,
        theme: null,
        params: [],
        slug: null,
    )));

    expect(resolve(RecordExtensionRenderContributionAction::class)->recorded())->toBe([]);
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
        ->and($records[0]->cacheable)->toBeFalse()
        ->and($records[0]->sensitiveOutput)->toBeFalse()
        ->and($records[0]->variesBy)->toBe(['site', 'locale']);

    resolve(RecordExtensionRenderContributionAction::class)->clear();
    $cacheableManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: $manifest->name,
        surfaces: ['frontend'],
        overrides: [
            'performance' => [
                'frontendRenderBudgetMs' => 0,
                'cacheTags' => ['extension:editorial-tools'],
                'cacheSafety' => [
                    'cacheable' => true,
                    'variesBy' => ['site', 'locale'],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [],
                    'queueInvalidation' => true,
                ],
            ],
        ],
    ));
    resolve(CapellPackageRegistry::class)->fill([$cacheableManifest->name => $cacheableManifest]);

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
