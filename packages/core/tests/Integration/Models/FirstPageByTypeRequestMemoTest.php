<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;

function firstPageByTypeFixture(Site $site, string $key): Page
{
    $blueprint = Blueprint::query()->where('key', $key)->first() ?? Blueprint::factory()->page()->createOne(['key' => $key]);
    $page = Page::factory()->createOne(['site_id' => $site->id, 'blueprint_id' => $blueprint->id]);
    Translation::factory()->translatable($page)->for($site->language)->create();
    PageUrl::factory()->page($page)->for($site)->create(['language_id' => $site->language->id]);

    return $page;
}

// Theme footer and blog footer both look up the contact page, SEO the results
// page: the repeated contact lookup must not query again in the same request.
it('looks up the first page of a type once per request for each type', function (): void {
    $site = Site::factory()->createOne();
    $language = $site->language;
    $contact = firstPageByTypeFixture($site, 'contact');
    $results = firstPageByTypeFixture($site, 'results');

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $foundContact = Page::getFirstPageByTypeForSite('contact', $site, $language);
        $afterContact = count(DB::getQueryLog());
        $foundResults = Page::getFirstPageByTypeForSite('results', $site, $language);
        $afterResults = count(DB::getQueryLog());
        $contactAgain = Page::getFirstPageByTypeForSite('contact', $site, $language);

        expect($foundContact?->id)->toBe($contact->id)
            ->and($foundResults?->id)->toBe($results->id)
            ->and($contactAgain?->id)->toBe($contact->id)
            ->and($afterResults)->toBe($afterContact * 2)
            ->and(count(DB::getQueryLog()))->toBe($afterResults);
    } finally {
        DB::disableQueryLog();
    }
});

it('reads the first page of a type again after a write or with a query modifier', function (): void {
    $site = Site::factory()->createOne();
    $language = $site->language;
    $first = firstPageByTypeFixture($site, 'contact');

    expect(Page::getFirstPageByTypeForSite('contact', $site, $language)?->id)->toBe($first->id);

    $second = firstPageByTypeFixture($site, 'contact');
    $first->delete();

    expect(Page::getFirstPageByTypeForSite('contact', $site, $language)?->id)->toBe($second->id)
        ->and(Page::getFirstPageByTypeForSite('contact', $site, $language, fn (BuilderContract $query) => $query->whereKeyNot($second->id)))->toBeNull();
});
