<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Agent\AgentPropertyCacheObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;

it('resolves page URL owners without lazy loading the pageable relation', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $page = Page::factory()->site($site)->createOne();

    Model::preventLazyLoading();

    try {
        $pageUrl = PageUrl::factory()
            ->page($page)
            ->site($site)
            ->createOne();

        expect(fn () => resolve(AgentPropertyCacheObserver::class)->saved($pageUrl))
            ->not->toThrow(LazyLoadingViolationException::class);
    } finally {
        Model::preventLazyLoading(false);
    }
});
