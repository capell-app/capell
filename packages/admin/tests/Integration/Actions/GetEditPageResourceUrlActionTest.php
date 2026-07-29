<?php

declare(strict_types=1);

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;

it('gracefully handles missing resource', function (): void {
    $page = Page::factory()->createOne();

    $url = GetEditPageResourceUrlAction::run($page);

    expect($url)->toBe('http://localhost/admin/pages/' . $page->id . '/edit');
});

it('loads the page blueprint before resolving its admin resource', function (): void {
    $page = Page::factory()->createOne()->fresh();

    expect($page->relationLoaded('blueprint'))->toBeFalse();

    Model::preventLazyLoading();

    try {
        $url = GetEditPageResourceUrlAction::run($page);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($url)->toBe('http://localhost/admin/pages/' . $page->id . '/edit')
        ->and($page->relationLoaded('blueprint'))->toBeTrue();
});
