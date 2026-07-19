<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Actions\ResolveFrontendResourcePlanAction;
use Capell\Frontend\Data\Assets\ExternalResourceSourceData;
use Capell\Frontend\Data\Assets\FrontendResourceActivationData;
use Capell\Frontend\Data\Assets\FrontendResourceActivationPlanData;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\Assets\ViteResourceSourceData;
use Capell\Frontend\Enums\CrossOrigin;
use Capell\Frontend\Enums\FrontendResourceHintKind;
use Capell\Frontend\Enums\FrontendResourcePlacement;
use Capell\Frontend\Enums\ReferrerPolicy;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;

it('resolves and dependency-orders eager resources across placements', function (): void {
    url()->useAssetOrigin('https://assets.example.test');

    $library = FrontendResourceData::classicScript(
        handle: 'capell-app/gallery:library',
        package: 'capell-app/gallery',
        source: new PublicResourceSourceData('vendor/gallery/library.js'),
    );
    $plugin = FrontendResourceData::classicScript(
        handle: 'capell-app/gallery:plugin',
        package: 'capell-app/gallery',
        source: new ExternalResourceSourceData(
            'https://cdn.example.com/plugin.js?v=2',
            'sha384-YWJjZA==',
            CrossOrigin::Anonymous,
            ReferrerPolicy::NoReferrer,
        ),
        placement: FrontendResourcePlacement::BodyEnd,
        dependsOn: [$library->handle],
        defer: false,
    );

    $plan = runBoundAction(ResolveFrontendResourcePlanAction::class, new ResolveFrontendResourcePlanAction(url(), resolve(Vite::class)), [
        new FrontendResourceContributionData($plugin),
        new FrontendResourceContributionData($library),
    ]);

    expect($plan->headResources)->toHaveCount(1)
        ->and($plan->headResources[0]->handle)->toBe($library->handle)
        ->and($plan->headResources[0]->url)->toBe('https://assets.example.test/vendor/gallery/library.js')
        ->and($plan->bodyEndResources)->toHaveCount(1)
        ->and($plan->bodyEndResources[0]->url)->toBe('https://cdn.example.com/plugin.js?v=2')
        ->and($plan->bodyEndResources[0]->integrity)->toBe('sha384-YWJjZA==')
        ->and($plan->cspOrigins['script-src'])->toContain('https://assets.example.test', 'https://cdn.example.com')
        ->and($plan->fingerprint)->toMatch('/\A[a-f0-9]{64}\z/');
});

it('expands transitive dependencies and detects missing handles', function (): void {
    $resource = FrontendResourceData::moduleScript(
        handle: 'capell-app/gallery:plugin',
        package: 'capell-app/gallery',
        source: new PublicResourceSourceData('vendor/gallery/plugin.js'),
        dependsOn: ['capell-app/gallery:missing'],
    );

    ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($resource),
    ]);
})->throws(FrontendResourcePlanException::class, 'Missing frontend resource dependency');

it('detects dependency cycles', function (): void {
    $first = FrontendResourceData::moduleScript('capell-app/gallery:first', 'capell-app/gallery', new PublicResourceSourceData('first.js'), dependsOn: ['capell-app/gallery:second']);
    $second = FrontendResourceData::moduleScript('capell-app/gallery:second', 'capell-app/gallery', new PublicResourceSourceData('second.js'), dependsOn: ['capell-app/gallery:first']);

    ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($first),
        new FrontendResourceContributionData($second),
    ]);
})->throws(FrontendResourcePlanException::class, 'cycle');

it('rejects asynchronous resources used as dependencies', function (): void {
    $async = FrontendResourceData::classicScript('capell-app/gallery:async', 'capell-app/gallery', new PublicResourceSourceData('async.js'), async: true);
    $dependent = FrontendResourceData::classicScript('capell-app/gallery:dependent', 'capell-app/gallery', new PublicResourceSourceData('dependent.js'), dependsOn: [$async->handle]);

    ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($async),
        new FrontendResourceContributionData($dependent),
    ]);
})->throws(FrontendResourcePlanException::class, 'Async resources cannot satisfy dependencies');

it('deduplicates compatible canonical URLs as aliases', function (): void {
    $first = FrontendResourceData::style('capell-app/gallery:first', 'capell-app/gallery', new PublicResourceSourceData('shared.css'));
    $second = FrontendResourceData::style('capell-app/gallery:second', 'capell-app/gallery', new PublicResourceSourceData('shared.css'));

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($first),
        new FrontendResourceContributionData($second),
    ]);

    expect($plan->headResources)->toHaveCount(1)
        ->and($plan->aliases)->toBe([$second->handle => $first->handle]);
});

it('preserves every independent lazy activation trigger for a shared resource', function (): void {
    $resource = FrontendResourceData::moduleScript(
        'capell-app/gallery:runtime',
        'capell-app/gallery',
        new PublicResourceSourceData('gallery.js'),
    );

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($resource, [
            new FrontendResourceActivationData('widget_a', PresentationLoadingStrategy::Visible),
        ]),
        new FrontendResourceContributionData($resource, [
            new FrontendResourceActivationData('widget_b', PresentationLoadingStrategy::Idle),
            new FrontendResourceActivationData('widget_c', PresentationLoadingStrategy::Interaction),
        ]),
    ]);

    expect($plan->headResources)->toBe([])
        ->and($plan->bodyEndResources)->toBe([])
        ->and($plan->lazyActivationGraphs)->toHaveCount(3)
        ->and(array_map(
            static fn (FrontendResourceActivationPlanData $activation): array => [$activation->target, $activation->loadingStrategy],
            $plan->lazyActivationGraphs,
        ))->toBe([
            ['widget_a', PresentationLoadingStrategy::Visible],
            ['widget_b', PresentationLoadingStrategy::Idle],
            ['widget_c', PresentationLoadingStrategy::Interaction],
        ]);
});

it('promotes a shared resource and its dependencies when any contribution is eager', function (): void {
    $library = FrontendResourceData::classicScript('capell-app/gallery:library', 'capell-app/gallery', new PublicResourceSourceData('library.js'));
    $plugin = FrontendResourceData::classicScript('capell-app/gallery:plugin', 'capell-app/gallery', new PublicResourceSourceData('plugin.js'), dependsOn: [$library->handle]);

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($library, [
            new FrontendResourceActivationData('widget_a', PresentationLoadingStrategy::Visible),
        ]),
        new FrontendResourceContributionData($plugin, [
            new FrontendResourceActivationData('widget_a', PresentationLoadingStrategy::Visible),
            new FrontendResourceActivationData('page', PresentationLoadingStrategy::Eager),
        ]),
    ]);

    expect(array_map(static fn (ResolvedFrontendResourceData $resource): string => $resource->handle, $plan->headResources))
        ->toBe([$library->handle, $plugin->handle])
        ->and($plan->lazyActivationGraphs)->toBe([]);
});

it('expands production vite entries with imported css and module preloads', function (): void {
    $buildDirectory = 'build/frontend-resource-test';
    File::ensureDirectoryExists(public_path($buildDirectory));
    File::put(public_path($buildDirectory . '/manifest.json'), json_encode([
        'resources/js/app.js' => [
            'file' => 'assets/app-123.js',
            'isEntry' => true,
            'css' => ['assets/app-123.css'],
            'imports' => ['_vendor.js', '_missing.js', '_invalid.js'],
        ],
        '_vendor.js' => [
            'file' => 'assets/vendor-456.js',
            'css' => ['assets/vendor-456.css'],
        ],
        '_invalid.js' => 'invalid manifest chunk',
    ], JSON_THROW_ON_ERROR));

    try {
        $resource = FrontendResourceData::moduleScript(
            'capell-app/frontend:application',
            'capell-app/frontend',
            new ViteResourceSourceData('resources/js/app.js', $buildDirectory),
        );
        $plan = ResolveFrontendResourcePlanAction::run([
            new FrontendResourceContributionData($resource),
        ]);

        expect(array_map(static fn (ResolvedFrontendResourceData $resolved): string => (string) $resolved->url, $plan->headResources))
            ->toBe([
                'http://localhost/' . $buildDirectory . '/assets/app-123.css',
                'http://localhost/' . $buildDirectory . '/assets/vendor-456.css',
                'http://localhost/' . $buildDirectory . '/assets/app-123.js',
            ])
            ->and($plan->hints)->toHaveCount(1)
            ->and($plan->hints[0]->href)->toBe('http://localhost/' . $buildDirectory . '/assets/vendor-456.js')
            ->and($plan->hints[0]->kind)->toBe(FrontendResourceHintKind::ModulePreload);
    } finally {
        File::deleteDirectory(public_path($buildDirectory));
    }
});

it('warns about missing external integrity by default and can require it', function (): void {
    $resource = FrontendResourceData::classicScript(
        'capell-app/gallery:external',
        'capell-app/gallery',
        new ExternalResourceSourceData('https://cdn.example.com/gallery.js'),
    );

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($resource),
    ]);

    expect($plan->diagnostics)->toHaveCount(1)
        ->and($plan->diagnostics[0]['code'])->toBe('external-integrity-missing')
        ->and($plan->diagnostics[0]['severity'])->toBe('warning')
        ->and($plan->diagnostics[0]['handle'])->toBe($resource->handle);

    config()->set('capell-frontend.external_resources.integrity_policy', 'require');

    expect(fn (): mixed => ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($resource),
    ]))->toThrow(FrontendResourcePlanException::class, 'requires an integrity hash');

    config()->set('capell-frontend.external_resources.integrity_policy', 'off');

    expect(ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData($resource),
    ])->diagnostics)->toBe([]);
});
