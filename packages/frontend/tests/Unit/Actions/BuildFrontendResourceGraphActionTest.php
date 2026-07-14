<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildFrontendResourceGraphAction;
use Capell\Frontend\Actions\ResolveFrontendResourcePlanAction;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;

it('builds server-side graph diagnostics with ownership placement and fingerprint', function (): void {
    $resource = FrontendResourceData::style(
        'capell-app/gallery:styles',
        'capell-app/gallery',
        new PublicResourceSourceData('vendor/gallery/gallery.css'),
    );
    $plan = resolve(ResolveFrontendResourcePlanAction::class)->handle([
        new FrontendResourceContributionData($resource),
    ]);

    $graph = BuildFrontendResourceGraphAction::run($plan);

    expect($graph['fingerprint'])->toBe($plan->fingerprint)
        ->and($graph['assets'])->toHaveCount(1)
        ->and($graph['assets'][0]['handle'])->toBe($resource->handle)
        ->and($graph['assets'][0]['package'])->toBe('capell-app/gallery')
        ->and($graph['assets'][0]['placement'])->toBe('head');
});
