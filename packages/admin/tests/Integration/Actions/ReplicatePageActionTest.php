<?php

declare(strict_types=1);

use Capell\Admin\Actions\ReplicatePageAction as AdminReplicatePageAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

it('replicates a page with relations', function (): void {
    $site = Site::factory()->createOne();
    $lang = Language::factory()->createOne();
    $page = Page::factory()->createOne([
        'site_id' => $site->id,
        'name' => 'Original',
    ]);

    /** @var Page $clone */
    $clone = AdminReplicatePageAction::run($page);

    expect($clone)->toBeInstanceOf(Page::class)
        ->and($clone->id)->not()->toBe($page->id)
        ->and($clone->name)->toContain('Original');
});
