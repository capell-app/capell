<?php

declare(strict_types=1);

use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\PreviewSiteDeletionImpactAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertSoftDeleted;

it('previews and soft deletes site-owned records', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    $page = Page::factory()->site($site)->layout($layout)->withTranslations()->create();
    $childPage = Page::factory()->site($site)->layout($layout)->parent($page)->withTranslations()->create();

    $pageUrl = PageUrl::factory()
        ->site($site)
        ->page($page)
        ->state(['language_id' => $site->language_id])
        ->create();

    $childPageUrl = PageUrl::factory()
        ->site($site)
        ->page($childPage)
        ->state(['language_id' => $site->language_id])
        ->create();

    $siteTranslation = $site->translations()->firstOrFail();
    $pageTranslation = $page->translations()->firstOrFail();
    $childPageTranslation = $childPage->translations()->firstOrFail();
    $siteDomain = $site->siteDomains()->firstOrFail();

    $impact = PreviewSiteDeletionImpactAction::run($site);

    expect($impact)
        ->pages->toBe(2)
        ->siteDomains->toBe(1)
        ->layouts->toBe(1)
        ->pageUrls->toBe(4)
        ->translations->toBe(3);

    DeleteSiteAction::run($site);

    assertSoftDeleted($site);
    assertSoftDeleted($layout);
    assertSoftDeleted($page);
    assertSoftDeleted($childPage);
    assertSoftDeleted($pageUrl);
    assertSoftDeleted($childPageUrl);
    assertSoftDeleted($siteDomain);
    assertModelExists($siteTranslation);
    assertModelExists($pageTranslation);
    assertModelExists($childPageTranslation);
});

it('restores only records deleted by the site deletion batch', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $activeLayout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    $previouslyDeletedLayout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    $activePage = Page::factory()->site($site)->layout($activeLayout)->withTranslations()->create();
    $previouslyDeletedPage = Page::factory()->site($site)->layout($previouslyDeletedLayout)->withTranslations()->create();

    $activePageUrl = PageUrl::factory()
        ->site($site)
        ->page($activePage)
        ->state(['language_id' => $site->language_id])
        ->create();

    $previouslyDeletedPageUrl = PageUrl::factory()
        ->site($site)
        ->page($previouslyDeletedPage)
        ->state(['language_id' => $site->language_id])
        ->create();

    $previouslyDeletedDomain = SiteDomain::factory()
        ->site($site)
        ->state(['language_id' => $site->language_id])
        ->create();

    $activeTranslation = $activePage->translations()->firstOrFail();
    $previouslyDeletedTranslation = $previouslyDeletedPage->translations()->firstOrFail();

    $previouslyDeletedPageUrl->delete();
    $previouslyDeletedPage->delete();
    $previouslyDeletedDomain->delete();
    $previouslyDeletedLayout->delete();

    DeleteSiteAction::run($site);

    Event::fake([
        FrontendSurrogateKeysInvalidated::class,
        PageSaved::class,
    ]);

    RestoreSiteAction::run(Site::withTrashed()->findOrFail($site->getKey()));

    assertModelExists($site->refresh());
    assertModelExists($activeLayout->refresh());
    assertModelExists($activePage->refresh());
    assertModelExists($activePageUrl->refresh());
    assertModelExists($activeTranslation->refresh());
    assertModelExists($previouslyDeletedTranslation->refresh());

    assertSoftDeleted($previouslyDeletedLayout);
    assertSoftDeleted($previouslyDeletedPage);
    assertSoftDeleted($previouslyDeletedPageUrl);
    assertSoftDeleted($previouslyDeletedDomain);

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === ['site-' . $site->getKey()],
    );

    Event::assertDispatched(
        PageSaved::class,
        fn (PageSaved $event): bool => $event->page instanceof Page && $event->page->is($activePage),
    );
});

it('cleans site-owned records on force delete', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    $page = Page::factory()->site($site)->layout($layout)->withTranslations()->create();
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->page($page)
        ->state(['language_id' => $site->language_id])
        ->create();
    $siteDomain = $site->siteDomains()->firstOrFail();
    $siteTranslation = $site->translations()->firstOrFail();
    $pageTranslation = $page->translations()->firstOrFail();

    $site->forceDelete();

    assertDatabaseMissing((new Site)->getTable(), ['id' => $site->getKey()]);
    assertDatabaseMissing((new Layout)->getTable(), ['id' => $layout->getKey()]);
    assertDatabaseMissing((new Page)->getTable(), ['id' => $page->getKey()]);
    assertDatabaseMissing((new PageUrl)->getTable(), ['id' => $pageUrl->getKey()]);
    assertDatabaseMissing((new SiteDomain)->getTable(), ['id' => $siteDomain->getKey()]);
    assertDatabaseMissing((new Translation)->getTable(), ['id' => $siteTranslation->getKey()]);
    assertDatabaseMissing((new Translation)->getTable(), ['id' => $pageTranslation->getKey()]);
});
