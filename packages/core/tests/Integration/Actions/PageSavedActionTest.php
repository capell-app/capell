<?php

declare(strict_types=1);

use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Event;

it('dispatches PageSaved with the page and raw form data', function (): void {
    Event::fake([PageSaved::class]);

    $site = Site::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id]);

    PageSavedAction::run($page, ['navigations' => [1, 2, 3], 'foo' => 'bar']);

    Event::assertDispatched(
        PageSaved::class,
        fn (PageSaved $event): bool => $event->page->is($page)
            && $event->formData === ['navigations' => [1, 2, 3], 'foo' => 'bar'],
    );
});

it('defaults form data to an empty array', function (): void {
    Event::fake([PageSaved::class]);

    $site = Site::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id]);

    PageSavedAction::run($page);

    Event::assertDispatched(
        PageSaved::class,
        fn (PageSaved $event): bool => $event->formData === [],
    );
});
