<?php

declare(strict_types=1);

use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;

it('sorts visible marketing studio actions by section and sort order', function (): void {
    CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
        key: 'test.hidden',
        label: 'Hidden',
        url: '/admin/hidden',
        section: MarketingStudioSectionEnum::Campaigns,
        sort: 1,
        visible: false,
    ));

    CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
        key: 'test.audience',
        label: 'Audience',
        url: '/admin/audience',
        section: MarketingStudioSectionEnum::Audience,
        sort: 10,
    ));

    CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
        key: 'test.campaigns',
        label: 'Campaigns',
        url: '/admin/campaigns',
        section: MarketingStudioSectionEnum::Campaigns,
        sort: 20,
    ));

    $actions = CapellAdmin::getMarketingStudioActions();

    expect(array_keys($actions))->toContain(
        MarketingStudioSectionEnum::Campaigns->value,
        MarketingStudioSectionEnum::Audience->value,
    )
        ->and(collect($actions[MarketingStudioSectionEnum::Campaigns->value])->pluck('key')->all())
        ->toContain('test.campaigns')
        ->not->toContain('test.hidden')
        ->and(collect($actions[MarketingStudioSectionEnum::Audience->value])->pluck('key')->all())
        ->toContain('test.audience');
});
