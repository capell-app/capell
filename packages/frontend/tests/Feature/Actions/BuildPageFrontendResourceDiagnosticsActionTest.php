<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Frontend\Actions\BuildPageFrontendResourceDiagnosticsAction;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\FrontendResourceContextData;

it('builds resource-plan ownership graph diagnostics and budget data for a page', function (): void {
    app()->bind('test.frontend-resource-diagnostics-contributor', fn (): FrontendResourceContributor => new class implements FrontendResourceContributor
    {
        public function resources(FrontendResourceContextData $context): array
        {
            return [new FrontendResourceContributionData(FrontendResourceData::style(
                'capell-app/gallery:styles',
                'capell-app/gallery',
                new PublicResourceSourceData('vendor/gallery/gallery.css'),
            ))];
        }
    });
    app()->tag('test.frontend-resource-diagnostics-contributor', FrontendResourceContributor::TAG);

    $diagnostics = BuildPageFrontendResourceDiagnosticsAction::run(Page::factory()->createOne());

    expect($diagnostics['context']['page'])->not->toBeNull()
        ->and($diagnostics['graph']['assets'])->toHaveCount(1)
        ->and($diagnostics['graph']['assets'][0]['source'])->toEndWith('/vendor/gallery/gallery.css')
        ->and($diagnostics['graph']['assets'][0]['package'])->toBe('capell-app/gallery')
        ->and($diagnostics['conflicts'])->toBe([])
        ->and($diagnostics['budgetResult']->passes)->toBeBool();
});
