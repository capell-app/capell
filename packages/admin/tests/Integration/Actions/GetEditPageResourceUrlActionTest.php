<?php

declare(strict_types=1);

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;

it('gracefully handles missing resource', function (): void {
    $page = Page::factory()->createOne();

    $url = GetEditPageResourceUrlAction::run($page);

    expect($url)->toBe('http://localhost/admin/pages/' . $page->id . '/edit');
});
