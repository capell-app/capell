<?php

declare(strict_types=1);

use Capell\Core\Contracts\Rendering\FrontendResourceContributor;
use Capell\Core\Data\Rendering\Assets\FrontendResourceActivationData;
use Capell\Core\Data\Rendering\Assets\FrontendResourceContributionData;
use Capell\Core\Data\Rendering\Assets\FrontendResourceData;
use Capell\Core\Data\Rendering\FrontendResourceContextData;
use Capell\Core\Data\Rendering\FrontendRuntimeManifestData;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Enums\Rendering\RenderingStrategyEnum;
use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Actions\ResolveFrontendResourcePlanAction;
use Capell\Frontend\Actions\SelectFrontendResourcesAction;
use Capell\Frontend\Contracts\FrontendResourceSelectionPolicy;
use Capell\Frontend\Data\Assets\ExternalResourceSourceData;
use Capell\Frontend\Data\Assets\FrontendResourceHintData;
use Capell\Frontend\Data\Assets\FrontendResourceSelectionData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Enums\FrontendResourceHintKind;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;

beforeEach(function (): void {
    app()->instance('test-resource-selection', new class implements FrontendResourceSelectionPolicy
    {
        #[Override]
        public function select(FrontendResourceContextData $context, FrontendResourceSelectionData $selection): FrontendResourceSelectionData
        {
            return new FrontendResourceSelectionData(array_values(array_filter(
                $selection->contributions,
                static fn (FrontendResourceContributionData $contribution): bool => str_starts_with($contribution->resource->handle, 'site/selected:'),
            )));
        }
    });
    app()->tag('test-resource-selection', FrontendResourceSelectionPolicy::TAG);
});

it('selects before dependency resolution and derives every output from selected declarations', function (): void {
    $kept = new FrontendResourceContributionData(FrontendResourceData::moduleScript(
        'site/selected:runtime',
        'site/selected',
        new PublicResourceSourceData('runtime.js'),
    ));
    $removed = new FrontendResourceContributionData(FrontendResourceData::moduleScript(
        'site/removed:runtime',
        'site/removed',
        new ExternalResourceSourceData('https://removed.example.test/runtime.js'),
        dependsOn: ['site/removed:missing'],
    ), [new FrontendResourceActivationData('removed-widget', PresentationLoadingStrategy::Visible)]);
    $hint = new FrontendResourceHintData(FrontendResourceHintKind::Preconnect, 'https://removed.example.test');
    $contributor = new class implements FrontendResourceContributor
    {
        /** @var list<FrontendResourceContributionData> */
        public array $contributions = [];

        #[Override]
        public function resources(FrontendResourceContextData $context): array
        {
            return $this->contributions;
        }
    };
    $contributor->contributions = [$kept, $removed];

    app()->instance('test-resource-contributor', $contributor);
    app()->tag('test-resource-contributor', FrontendResourceContributor::TAG);

    $context = new FrontendRenderContextData(null, null, null, null, null);
    $selected = BuildPublicPageRenderDataAction::run($context)->resourcePlan;
    $contributor->contributions = [$kept];
    $expected = BuildPublicPageRenderDataAction::run($context)->resourcePlan;

    expect($selected->headResources)->toHaveCount(1)
        ->and($selected->headResources[0]->handle)->toBe('site/selected:runtime')
        ->and($selected->lazyActivationGraphs)->toBe([])
        ->and($selected->hints)->toBe([])
        ->and($selected->diagnostics)->toBe([])
        ->and($selected->aliases)->toBe([])
        ->and($selected->cspOrigins['script-src'])->not->toContain('https://removed.example.test')
        ->and($selected->fingerprint)->toBe($expected->fingerprint);

    $selection = SelectFrontendResourcesAction::run(
        new FrontendResourceContextData(null, null, null, null, null, FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly)),
        new FrontendResourceSelectionData([$kept, $removed], [$hint]),
    );
    expect($selection->hints)->toBe([])
        ->and(ResolveFrontendResourcePlanAction::run($selection->contributions, $selection->hints)->fingerprint)->toBe($expected->fingerprint);
});

it('rejects a retained resource whose dependency was excluded', function (): void {
    $kept = new FrontendResourceContributionData(FrontendResourceData::moduleScript(
        'site/selected:runtime',
        'site/selected',
        new PublicResourceSourceData('runtime.js'),
        dependsOn: ['site/removed:library'],
    ));
    $removed = new FrontendResourceContributionData(FrontendResourceData::moduleScript(
        'site/removed:library',
        'site/removed',
        new PublicResourceSourceData('library.js'),
    ));
    $selection = SelectFrontendResourcesAction::run(
        new FrontendResourceContextData(null, null, null, null, null, FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly)),
        new FrontendResourceSelectionData([$kept, $removed]),
    );
    ResolveFrontendResourcePlanAction::run($selection->contributions, $selection->hints);
})->throws(FrontendResourcePlanException::class, 'Missing frontend resource dependency');
