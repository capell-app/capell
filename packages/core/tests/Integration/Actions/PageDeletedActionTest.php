<?php

declare(strict_types=1);

use Capell\Core\Actions\PageDeletedAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\PageDeleted;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

it('perform-builder side effects on page deletion', function (): void {
    $site = Site::factory()->createOne();
    Language::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id]);

    Cache::driver('array')->forever(CacheEnum::RelationExists->value . '-' . Page::class . '-' . $page->id . '-translations', true);

    PageDeletedAction::run($page);

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::RelationExists->value . '-' . Page::class . '-' . $page->id . '-translations');
});

it('dispatches PageDeleted with form data', function (): void {
    Event::fake([PageDeleted::class]);

    $site = Site::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id]);

    PageDeletedAction::run($page, ['custom_key' => 'value']);

    Event::assertDispatched(
        PageDeleted::class,
        fn (PageDeleted $event): bool => $event->page->is($page) && $event->formData === ['custom_key' => 'value'],
    );
});
