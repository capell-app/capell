<?php

declare(strict_types=1);

use Capell\Core\Data\LinkableContentData;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Links\LinkableContentRegistry;
use Capell\Core\Support\Links\PageLinkableContentProvider;
use Illuminate\Support\Facades\Event;

it('registers pages as linkable content', function (): void {
    $registry = resolve(LinkableContentRegistry::class);

    expect($registry->provider('pages'))->toBeInstanceOf(PageLinkableContentProvider::class);
});

it('returns normalized page linkable content data scoped by site and language', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->create();
    $otherLanguage = Language::factory()->createOne();
    $otherSite = Site::factory()->language($otherLanguage)->create();

    $page = Page::factory()->site($site)->create(['name' => 'About fallback']);
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->create(['url' => '/about']);

    Translation::factory()
        ->translatable($page)
        ->language($language)
        ->create([
            'title' => 'About Capell',
            'meta' => ['link_text' => 'About us', 'slug' => 'about'],
        ]);

    $otherPage = Page::factory()->site($otherSite)->create();
    PageUrl::factory()
        ->site($otherSite)
        ->language($otherLanguage)
        ->page($otherPage)
        ->create(['url' => '/other']);

    $options = resolve(LinkableContentRegistry::class)->options($site->id, $language->id);
    $option = expectPresent($options->first());

    expect($options)->toHaveCount(1)
        ->and($option)->toBeInstanceOf(LinkableContentData::class)
        ->and($option->type)->toBe('page_url')
        ->and($option->id)->toBe($pageUrl->id)
        ->and($option->label)->toBe('About us')
        ->and($option->url)->toBe('/about')
        ->and($option->status)->toBeTrue()
        ->and($option->site_id)->toBe($site->id)
        ->and($option->language_id)->toBe($language->id);
});

it('only returns the default page url when aliases and redirects exist for the same page', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->create();
    $page = Page::factory()->site($site)->create(['name' => 'About']);

    $defaultPageUrl = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->create(['url' => '/about']);

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->create([
            'url' => '/about-alias',
            'type' => UrlTypeEnum::Alias,
        ]);

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->create([
            'url' => '/about-old',
            'target_url' => '/about',
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'type' => UrlTypeEnum::Redirect,
        ]);

    $options = resolve(LinkableContentRegistry::class)->options($site->id, $language->id);
    $option = expectPresent($options->first());

    expect($options)->toHaveCount(1)
        ->and($option->id)->toBe($defaultPageUrl->id)
        ->and($option->url)->toBe('/about');
});

it('returns one default page url per language when language is unscoped', function (): void {
    $english = Language::factory()->createOne();
    $welsh = Language::factory()->createOne();
    $site = Site::factory()->language($english)->create();
    $page = Page::factory()->site($site)->create(['name' => 'About fallback']);

    $englishPageUrl = PageUrl::factory()
        ->site($site)
        ->language($english)
        ->page($page)
        ->create(['url' => '/about']);

    $welshPageUrl = PageUrl::factory()
        ->site($site)
        ->language($welsh)
        ->page($page)
        ->create(['url' => '/amdanom-ni']);

    Translation::factory()
        ->translatable($page)
        ->language($english)
        ->create([
            'title' => 'About Capell',
            'meta' => ['link_text' => 'About us', 'slug' => 'about'],
        ]);

    Translation::factory()
        ->translatable($page)
        ->language($welsh)
        ->create([
            'title' => 'Amdanom Capell',
            'meta' => ['link_text' => 'Amdanom ni', 'slug' => 'amdanom-ni'],
        ]);

    $options = resolve(LinkableContentRegistry::class)->options($site->id);

    expect($options)->toHaveCount(2)
        ->and($options->pluck('id')->all())->toEqualCanonicalizing([
            $englishPageUrl->id,
            $welshPageUrl->id,
        ])
        ->and($options->pluck('language_id')->all())->toEqualCanonicalizing([
            $english->id,
            $welsh->id,
        ])
        ->and($options->pluck('label')->all())->toEqualCanonicalizing([
            'About us',
            'Amdanom ni',
        ]);
});

it('dispatches a page url changed event when the url changes', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->create();
    $page = Page::factory()->site($site)->create();
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->create(['url' => '/about']);

    Event::fake([PageUrlChanged::class]);

    $pageUrl->update(['url' => '/about-us']);

    Event::assertDispatched(
        PageUrlChanged::class,
        fn (PageUrlChanged $event): bool => $event->page_url_id === $pageUrl->id
            && $event->page_id === $page->id
            && $event->site_id === $site->id
            && $event->language_id === $language->id
            && $event->old_url === '/about'
            && $event->new_url === '/about-us',
    );
});

it('does not dispatch a page url changed event when url values are unchanged', function (): void {
    $pageUrl = PageUrl::factory()->createOne(['url' => '/about']);

    Event::fake([PageUrlChanged::class]);

    $pageUrl->save();

    Event::assertNotDispatched(PageUrlChanged::class);
});

it('dispatches a page url changed event when the redirect target changes', function (): void {
    $pageUrl = PageUrl::factory()
        ->manualRedirect()
        ->create([
            'url' => '/old-about',
            'target_url' => '/about',
        ]);

    Event::fake([PageUrlChanged::class]);

    $pageUrl->update(['target_url' => '/about-us']);

    Event::assertDispatched(
        PageUrlChanged::class,
        fn (PageUrlChanged $event): bool => $event->page_url_id === $pageUrl->id
            && $event->page_id === null
            && $event->old_url === '/old-about'
            && $event->new_url === '/old-about',
    );
});
