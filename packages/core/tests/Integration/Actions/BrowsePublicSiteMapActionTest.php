<?php

declare(strict_types=1);

use Capell\Core\Actions\Agent\BrowsePublicSiteMapAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;

it('lists only translated published pages with usable same-site urls', function (): void {
    $fixture = createSiteMapFixture('/home');

    $visible = Page::factory()->site($fixture['site'])->create(['visible_from' => now()->subDay()]);
    Translation::factory()->translatable($visible)->language($fixture['language'])->create(['title' => 'Visible page']);
    PageUrl::query()->where('pageable_type', $visible->getMorphClass())->where('pageable_id', $visible->getKey())
        ->where('language_id', $fixture['language']->getKey())->firstOrFail()->update(['url' => '/visible']);

    $pending = Page::factory()->site($fixture['site'])->create(['visible_from' => now()->addDay()]);
    Translation::factory()->translatable($pending)->language($fixture['language'])->create(['title' => 'Pending page']);
    PageUrl::query()->where('pageable_type', $pending->getMorphClass())->where('pageable_id', $pending->getKey())
        ->where('language_id', $fixture['language']->getKey())->firstOrFail()->update(['url' => '/pending']);

    $redirectPage = Page::factory()->site($fixture['site'])->create(['visible_from' => now()->subDay()]);
    PageUrl::factory()->page($redirectPage)->site($fixture['site'])->language($fixture['language'])
        ->create(['url' => '/redirect', 'type' => UrlTypeEnum::Redirect]);

    $result = BrowsePublicSiteMapAction::run($fixture['site'], $fixture['language']);

    expect($result->total())->toBe(2)
        ->and($result->getCollection()->pluck('url')->all())->toBe(['/home', '/visible'])
        ->and($result->getCollection()[1]['title'])->toBe('Visible page');
});

it('requires the requested language translation', function (): void {
    $fixture = createSiteMapFixture('/home');
    $otherLanguage = Language::factory()->createOne();
    $page = Page::factory()->site($fixture['site'])->create(['visible_from' => now()->subDay()]);
    Translation::factory()->translatable($page)->language($otherLanguage)->create(['title' => 'Other language']);

    PageUrl::factory()->page($page)->site($fixture['site'])->language($fixture['language'])
        ->create(['url' => '/untranslated']);

    $result = BrowsePublicSiteMapAction::run($fixture['site'], $fixture['language']);

    expect($result->getCollection()->pluck('url')->all())->toBe(['/home']);
});

it('excludes urls whose same-site domain is disabled', function (): void {
    $fixture = createSiteMapFixture('/home');
    $domain = SiteDomain::query()->where('site_id', $fixture['site']->getKey())
        ->where('language_id', $fixture['language']->getKey())->firstOrFail();
    $domain->update(['status' => false]);

    $result = BrowsePublicSiteMapAction::run($fixture['site'], $fixture['language']);

    expect($result->total())->toBe(0);
});

/** @return array{site: Site, language: Language, page: Page} */
function createSiteMapFixture(string $url): array
{
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    SiteDomain::factory()->site($site)->language($language)->create();
    $page = Page::factory()->site($site)->create(['visible_from' => now()->subDay()]);
    Translation::factory()->translatable($page)->language($language)->create(['title' => 'Home']);
    PageUrl::query()->where('pageable_type', $page->getMorphClass())->where('pageable_id', $page->getKey())
        ->where('language_id', $language->getKey())->firstOrFail()->update(['url' => $url]);

    return ['site' => $site, 'language' => $language, 'page' => $page];
}
