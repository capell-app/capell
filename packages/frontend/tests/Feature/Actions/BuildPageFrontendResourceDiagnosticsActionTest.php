<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Frontend\Actions\BuildPageFrontendResourceDiagnosticsAction;
use Capell\Frontend\Contracts\FrontendAssetContributor;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;

it('builds frontend resource graph conflicts and budget data for a page', function (): void {
    $registry = new FrontendResourceRegistry;
    $registry->register(
        key: 'package.gallery',
        label: 'Gallery resources',
        assets: ['resources/css/gallery.css'],
        package: 'capell-app/gallery',
        defaultBuildPath: 'build',
    );
    app()->instance(FrontendResourceRegistry::class, $registry);

    app()->bind('test.frontend-resource-diagnostics-contributor', fn (): FrontendAssetContributor => new class implements FrontendAssetContributor
    {
        public function requirements(FrontendAssetContextData $context): array
        {
            return [
                new FrontendAssetRequirementData(
                    handle: 'gallery-css',
                    kind: FrontendAssetRequirementData::KIND_CSS,
                    source: 'resources/css/gallery.css',
                    buildPath: 'build',
                ),
            ];
        }
    });
    app()->tag('test.frontend-resource-diagnostics-contributor', FrontendAssetContributor::TAG);

    $diagnostics = BuildPageFrontendResourceDiagnosticsAction::run(Page::factory()->createOne());

    expect($diagnostics['context']['page'])->not->toBeNull()
        ->and($diagnostics['graph']['assets'])->toHaveCount(1)
        ->and($diagnostics['graph']['assets'][0]['source'])->toBe('resources/css/gallery.css')
        ->and($diagnostics['graph']['assets'][0]['reasons'][0])->toBe('Resource group package.gallery includes resources/css/gallery.css.')
        ->and($diagnostics['conflicts'])->toBe([])
        ->and($diagnostics['budgetResult']->passes)->toBeBool();
});
