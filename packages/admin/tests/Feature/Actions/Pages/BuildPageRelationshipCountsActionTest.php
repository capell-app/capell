<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BuildPageRelationshipCountsAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;

it('builds child and page url relationship counts from preloaded relations', function (): void {
    $page = Page::factory()->create();
    Page::factory()->count(2)->parent($page)->create();
    PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)->create();

    $data = BuildPageRelationshipCountsAction::run($page->load(['children', 'pageUrls']));

    expect($data->childrenCount)->toBe(2)
        ->and($data->urlCount)->toBe(1)
        ->and(collect($data->counts())->pluck('key')->all())->toBe(['children', 'urls']);
});
