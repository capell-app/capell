<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Builder;

it('creates default pages for a site', function (): void {
    $site = Site::factory()->createOne();
    $lang = Language::factory()->createOne();

    CreateDefaultPagesAction::run($site);

    expect(Page::query()->where('site_id', $site->id)->exists())->toBeTrue();
});

it('creates the welcome landing page', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $languages = $language->newCollection([$language]);

    CreateDefaultPagesAction::run($site, $languages, ['welcome']);

    $welcome = Page::query()
        ->where('site_id', $site->id)
        ->whereHas('translations', function (Builder $query): void {
            $query->where('meta->slug', 'welcome');
        })
        ->first();

    expect($welcome)->not->toBeNull();
});
