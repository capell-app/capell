<?php

declare(strict_types=1);

use Capell\Core\Actions\UpdatePageUrlAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

it('updates page URL and persists', function (): void {
    $site = Site::factory()->createOne();
    $lang = Language::factory()->createOne();
    $page = Page::factory()->site($site)->create(['name' => 'About']);

    $translation = Translation::factory()
        ->state([
            'translatable_type' => $page->getMorphClass(),
            'translatable_id' => $page->id,
            'language_id' => $lang->id,
            'title' => $page->name,
        ])
        ->create();

    UpdatePageUrlAction::run($site, $translation);

    $translation->pageUrl->refresh();

    expect($translation->pageUrl->url)->toBe('/about');
});
