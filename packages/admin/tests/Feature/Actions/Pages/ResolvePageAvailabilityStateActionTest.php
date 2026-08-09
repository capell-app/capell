<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ResolvePageAvailabilityStateAction;
use Capell\Admin\Tests\Unit\Support\Pages\Fixtures\NonPageablePageForResolverTest;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Collection;

it('resolves no active url when every page url is disabled', function (): void {
    $page = Page::factory()->create();
    PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)->create(['status' => false]);
    PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)->create(['status' => false]);

    $data = ResolvePageAvailabilityStateAction::run($page->load('pageUrls'));

    expect($data->totalUrlCount)->toBe(2)
        ->and($data->activeUrlCount)->toBe(0)
        ->and($data->disabledUrlCount)->toBe(2)
        ->and($data->state()?->key)->toBe('no_active_url');
});

it('resolves partial url availability', function (): void {
    $page = Page::factory()->create();
    PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)->create(['status' => true]);
    PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)->create(['status' => false]);

    $data = ResolvePageAvailabilityStateAction::run($page->load('pageUrls'));

    expect($data->state()?->key)->toBe('some_urls_disabled')
        ->and($data->activeUrlCount)->toBe(1)
        ->and($data->disabledUrlCount)->toBe(1);
});

it('resolves a custom page-type model that implements Pageable without extending Page', function (): void {
    // Capell\Blog\Models\Article and Capell\Events\Models\Event both implement
    // Pageable this way in production: a plain Model, not a Page subclass.
    // The fixture's own pageUrl()/pageUrls() relations are unimplemented
    // stubs (see NonPageablePageForResolverTest), so the relation is set
    // directly rather than queried -- this test is only proving the action
    // accepts a non-Page Pageable model, not exercising real polymorphic
    // relation matching.
    $page = Page::factory()->create();
    $customPageTypeRecord = NonPageablePageForResolverTest::query()->where('id', $page->id)->firstOrFail();
    $customPageTypeRecord->setRelation('pageUrls', new Collection);

    $data = ResolvePageAvailabilityStateAction::run($customPageTypeRecord);

    expect($data->totalUrlCount)->toBe(0)
        ->and($data->state()?->key)->toBe('no_active_url');
});
