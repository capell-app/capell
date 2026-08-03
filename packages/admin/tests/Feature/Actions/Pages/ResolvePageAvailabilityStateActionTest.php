<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ResolvePageAvailabilityStateAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;

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
