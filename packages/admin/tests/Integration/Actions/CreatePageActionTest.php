<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreatePageAction as AdminCreatePageAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

it('creates a page with validated payload', function (): void {
    $site = Site::factory()->createOne();

    $payload = [
        'site_id' => $site->id,
        'name' => 'New Page',
        'blueprint_id' => Blueprint::factory()->page()->create()->id,
        'layout_id' => Layout::factory()->createOne()->id,
    ];

    /** @var Page $page */
    $page = AdminCreatePageAction::run($payload);

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->name)->toBe('New Page');
});
