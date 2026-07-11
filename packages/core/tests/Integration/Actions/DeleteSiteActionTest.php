<?php

declare(strict_types=1);

use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

use function Pest\Laravel\assertSoftDeleted;

it('returns true without recording a new batch when the site is already trashed', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $site->delete();

    expect($site->trashed())->toBeTrue();

    $result = DeleteSiteAction::run($site);

    expect($result)->toBeTrue()
        ->and(DeletionBatch::query()->where('root_type', Site::class)->where('root_id', $site->getKey())->count())->toBe(0);
});

it('records every soft-deleted owned model in a single deletion batch', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    $page = Page::factory()->site($site)->layout($layout)->withTranslations()->create();
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->page($page)
        ->state(['language_id' => $site->language_id])
        ->create();
    $siteDomain = $site->siteDomains()->firstOrFail();

    $result = DeleteSiteAction::run($site);

    expect($result)->toBeTrue();

    assertSoftDeleted($site);
    assertSoftDeleted($layout);
    assertSoftDeleted($page);
    assertSoftDeleted($pageUrl);
    assertSoftDeleted($siteDomain);

    $batch = DeletionBatch::query()
        ->where('root_type', Site::class)
        ->where('root_id', $site->getKey())
        ->firstOrFail();

    $recorded = $batch->records()
        ->get()
        ->map(fn ($record): array => [$record->model_type, (int) $record->model_id])
        ->all();

    expect($recorded)
        ->toContain([Site::class, $site->getKey()])
        ->toContain([Layout::class, $layout->getKey()])
        ->toContain([Page::class, $page->getKey()])
        ->toContain([PageUrl::class, $pageUrl->getKey()])
        ->toContain([SiteDomain::class, $siteDomain->getKey()]);
});
