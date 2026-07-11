<?php

declare(strict_types=1);

use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Tests\Fixtures\Models\User;

it('creates home and error pages with translations for the supplied languages', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $site = Site::factory()->for($english, 'language')->create(['name' => 'capell cms']);
    $languages = collect([$english, $french]);

    $creator = resolve(PageCreator::class);
    $homePage = $creator->createHomePage($site, $languages);
    $errorPage = $creator->createErrorPage($site, $languages);
    $maintenancePage = $creator->createMaintenancePage($site, $languages);

    $englishHomeTranslation = $homePage->translations()->firstWhere('language_id', $english->id);

    expect($englishHomeTranslation)->toBeInstanceOf(Translation::class);
    assert($englishHomeTranslation instanceof Translation);

    expect($homePage)->toBeInstanceOf(Page::class)
        ->and($homePage->name)->toBe('Home')
        ->and($homePage->order)->toBe(1)
        ->and($homePage->layout->key)->toBe(LayoutEnum::Home->value)
        ->and($homePage->blueprint->key)->toBe(PageTypeEnum::Home->value)
        ->and($homePage->blueprint->name)->toBe('Home')
        ->and($homePage->translations()->pluck('title', 'language_id')->all())->toBe([
            $english->id => 'Capell Cms',
            $french->id => 'Capell Cms',
        ])
        ->and($englishHomeTranslation->meta)->toMatchArray([
            'slug' => '/',
            'label' => 'Home',
            'title' => ':site',
        ]);

    expect($errorPage->name)->toBe('Page Not Found')
        ->and($errorPage->layout->key)->toBe(LayoutEnum::System->value)
        ->and($errorPage->blueprint->key)->toBe(PageTypeEnum::NotFound->value)
        ->and($errorPage->blueprint->name)->toBe('Page Not Found')
        ->and($errorPage->meta)->toBe(['robots' => ['noindex' => true]])
        ->and($errorPage->translations()->get()->pluck('content')->unique()->values()->all())->toBe([
            '<p>The URL you have reached does not exist. Check the address for typos or a close match.</p><p>Return to the previous page, or <a href="/">go to the homepage</a>.</p>',
        ])
        ->and($errorPage->translations()->pluck('title', 'language_id')->all())->toBe([
            $english->id => 'Page Not Found',
            $french->id => 'Page Not Found',
        ]);

    $englishErrorTranslation = $errorPage->translations()->firstWhere('language_id', $english->id);

    expect($englishErrorTranslation)->toBeInstanceOf(Translation::class);
    assert($englishErrorTranslation instanceof Translation);

    $errorStatusCopy = $englishErrorTranslation->meta['error_status_copy'] ?? null;

    expect($errorStatusCopy)->toBeArray()
        ->and(array_keys($errorStatusCopy))->toBe([401, 402, 403, 404, 419, 429, 500, 503])
        ->and($errorStatusCopy[500])->toBe([
            'headline' => 'Something went wrong',
            'description' => 'We hit an unexpected problem. Please try again shortly, and contact us if it continues.',
        ])
        ->and($errorStatusCopy[404]['headline'])->toBe('Page not found');

    expect($maintenancePage->name)->toBe('Maintenance')
        ->and($maintenancePage->layout->key)->toBe(LayoutEnum::System->value)
        ->and($maintenancePage->blueprint->key)->toBe(PageTypeEnum::Maintenance->value)
        ->and($maintenancePage->translations()->pluck('title', 'language_id')->all())->toBe([
            $english->id => 'Maintenance',
            $french->id => 'Maintenance',
        ]);
});

it('does not create duplicate home page urls when default pages are seeded more than once', function (): void {
    $english = Language::factory()->english()->create();
    $site = Site::factory()->for($english, 'language')->create(['name' => 'capell cms']);
    $languages = collect([$english]);

    $creator = resolve(PageCreator::class);

    $firstHomePage = $creator->createHomePage($site, $languages);
    $secondHomePage = $creator->createHomePage($site, $languages);

    expect($secondHomePage->is($firstHomePage))->toBeTrue()
        ->and(PageUrl::query()
            ->where('site_id', $site->id)
            ->where('language_id', $english->id)
            ->where('url', '/')
            ->count())->toBe(1);
});

it('reuses an existing root url page when creating the default home page', function (): void {
    $english = Language::factory()->english()->create();
    $site = Site::factory()->for($english, 'language')->create(['name' => 'capell cms']);
    $existingPage = Page::factory()->site($site)->create(['name' => 'Existing root']);

    PageUrl::factory()
        ->site($site)
        ->language($english)
        ->page($existingPage)
        ->create(['url' => '/']);

    $homePage = resolve(PageCreator::class)->createHomePage($site, collect([$english]));

    expect($homePage->is($existingPage))->toBeTrue()
        ->and(Page::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(PageUrl::query()
            ->where('site_id', $site->id)
            ->where('language_id', $english->id)
            ->where('url', '/')
            ->count())->toBe(1)
        ->and($homePage->refresh()->name)->toBe('Home')
        ->and($homePage->blueprint->key)->toBe(PageTypeEnum::Home->value)
        ->and($homePage->layout->key)->toBe(LayoutEnum::Home->value);
});

it('rejects duplicate active page urls for the same site and language', function (): void {
    $english = Language::factory()->english()->create();
    $site = Site::factory()->for($english, 'language')->create();
    $firstPage = Page::factory()->site($site)->create();
    $secondPage = Page::factory()->site($site)->create();

    PageUrl::factory()
        ->site($site)
        ->language($english)
        ->page($firstPage)
        ->create(['url' => '/']);

    expect(fn (): PageUrl => PageUrl::factory()
        ->site($site)
        ->language($english)
        ->page($secondPage)
        ->create(['url' => '/']))
        ->toThrow(Exception::class, 'Page URL "/" already exists');
});

it('allows a new active page url when the existing matching url is inactive', function (): void {
    $english = Language::factory()->english()->create();
    $site = Site::factory()->for($english, 'language')->create();
    $retiredPage = Page::factory()->site($site)->create();
    $activePage = Page::factory()->site($site)->create();

    PageUrl::factory()
        ->site($site)
        ->language($english)
        ->page($retiredPage)
        ->create([
            'url' => '/',
            'status' => false,
        ]);

    $activeUrl = PageUrl::factory()
        ->site($site)
        ->language($english)
        ->page($activePage)
        ->create(['url' => '/']);

    expect($activeUrl->status)->toBeTrue()
        ->and(PageUrl::query()
            ->where('site_id', $site->id)
            ->where('language_id', $english->id)
            ->where('url', '/')
            ->where('status', true)
            ->count())->toBe(1);
});

it('creates a custom page with translated metadata and preserves creator user stamps', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $site = Site::factory()->for($english, 'language')->create();
    $layout = Layout::factory()->createOne(['key' => 'article']);
    $type = Blueprint::factory()->page()->create(['key' => 'article']);
    $user = User::factory()->createOne();

    $page = resolve(PageCreator::class)->createPage([
        'name' => 'About Capell',
        'layout_id' => $layout->id,
        'blueprint_id' => $type->id,
        'image_id' => 123,
        'meta' => [
            'show_hero' => false,
            'hero_style' => 'compact',
        ],
        'visible_from' => '2026-05-07 09:00:00',
        'user_id' => $user->id,
        'translations' => [
            'en' => [
                'title' => 'About Capell',
                'content' => '<p>English body.</p>',
                'summary' => 'English summary',
                'link_text' => 'Read more',
                'meta' => ['title' => 'About title'],
            ],
            'fr' => [
                'title' => 'A propos',
                'slug' => 'a-propos',
                'summary' => 'French summary',
            ],
        ],
    ], $site, collect([$english, $french]));

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->name)->toBe('About Capell')
        ->and($page->layout_id)->toBe($layout->id)
        ->and($page->blueprint_id)->toBe($type->id)
        ->and($page->meta)->toBe([
            'show_hero' => false,
            'hero_style' => 'compact',
            'image_id' => 123,
        ])
        ->and((string) $page->visible_from)->toContain('2026-05-07');

    $englishTranslation = $page->translations()->firstWhere('language_id', $english->id);
    $frenchTranslation = $page->translations()->firstWhere('language_id', $french->id);

    expect($englishTranslation)->toBeInstanceOf(Translation::class)
        ->and($frenchTranslation)->toBeInstanceOf(Translation::class);
    assert($englishTranslation instanceof Translation);
    assert($frenchTranslation instanceof Translation);

    expect($englishTranslation->title)->toBe('About Capell')
        ->and($englishTranslation->content)->toBe('<p>English body.</p>')
        ->and($englishTranslation->created_by)->toBe($user->id)
        ->and($englishTranslation->updated_by)->toBe($user->id)
        ->and($englishTranslation->meta)->toMatchArray([
            'title' => 'About title',
            'summary' => 'English summary',
            'link_text' => 'Read more',
            'slug' => 'about-capell',
        ])
        ->and($frenchTranslation->title)->toBe('A propos')
        ->and($frenchTranslation->meta)->toMatchArray([
            'summary' => 'French summary',
            'slug' => 'a-propos',
        ]);
});
