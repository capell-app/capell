<?php

declare(strict_types=1);

use Capell\Core\Data\SiteAccessContextData;
use Capell\Frontend\Contracts\SiteAccessExemptionContributor;
use Capell\Frontend\Support\SiteAccess\SiteAccessExemptionRegistry;
use Illuminate\Http\Request;

it('requires a code contributor to exempt a site access request', function (): void {
    $context = new SiteAccessContextData(Request::create('/machine/feed'));
    $allow = new class implements SiteAccessExemptionContributor
    {
        public function exempts(SiteAccessContextData $context): bool
        {
            return $context->request->path() === 'machine/feed';
        }
    };
    $deny = new class implements SiteAccessExemptionContributor
    {
        public function exempts(SiteAccessContextData $context): bool
        {
            return false;
        }
    };

    expect(new SiteAccessExemptionRegistry([$deny])->exempts($context))->toBeFalse()
        ->and(new SiteAccessExemptionRegistry([$deny, $allow])->exempts($context))->toBeTrue();
});
