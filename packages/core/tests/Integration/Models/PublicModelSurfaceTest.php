<?php

declare(strict_types=1);

use Capell\Core\Actions\GenerateThemeImageAction;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

it('hydrates public page model behavior across publish windows urls ordering and metadata', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $french], siteDomainData: ['domain' => 'example.test', 'scheme' => 'https', 'path' => '/'])
        ->createOne();

    $homeType = Blueprint::factory()->page()->createOne(['key' => 'home', 'name' => 'Home type']);
    $articleType = Blueprint::factory()->page()->createOne([
        'key' => 'article',
        'name' => 'Article type',
        'meta' => [
            'cache_frequency' => 'always',
            'content_structure' => ContentStructure::Blocks->value,
            'cache_time' => CacheTime::Hourly->value,
            'url_params' => ['year' => 'int'],
        ],
    ]);

    $home = Page::factory()
        ->home()
        ->site($site)
        ->type($homeType)
        ->published()
        ->withTranslations($english, ['title' => 'Home', 'content' => 'Welcome'], slug: '/')
        ->createOne(['order' => 1]);
    $home->pageUrls()->whereBelongsTo($english)->where('url', '/')->firstOrFail();

    $parent = Page::factory()
        ->site($site)
        ->type($articleType)
        ->published(now()->subDays(4)->toImmutable())
        ->withTranslations($english, ['title' => 'Parent', 'content' => 'Parent content'], slug: 'parent')
        ->createOne(['order' => 2]);
    $parent->pageUrls()->whereBelongsTo($english)->where('url', '/parent')->firstOrFail();

    $child = Page::factory()
        ->site($site)
        ->parent($parent)
        ->type($articleType)
        ->published(now()->subDays(2)->toImmutable())
        ->withTranslations($english, [
            'title' => 'Child',
            'content' => '<p>Child body</p>',
        ], slug: 'child')
        ->createOne(['order' => 3, 'meta' => ['related' => [$parent->getKey()]]]);
    $childUrl = $child->pageUrls()->whereBelongsTo($english)->where('url', '/parent/child')->firstOrFail();
    $childTranslation = expectPresent($child->translations()->whereBelongsTo($english)->first());
    $childTranslation->forceFill([
        'meta' => [
            'slug' => 'child',
            'summary' => '',
            'label' => '',
            'link_text' => 'Read child',
            'description' => '',
            'keywords' => 'cms,capell',
            'title' => 'SEO Child',
        ],
    ])->save();

    $pending = Page::factory()->site($site)->type($articleType)->pending()->createOne(['name' => 'Pending']);
    $expired = Page::factory()->site($site)->type($articleType)->expired()->createOne(['name' => 'Expired']);

    $site->load(['language', 'siteDomains.language', 'theme']);
    $child->load(['site.siteDomains', 'translation', 'pageUrl', 'blueprint', 'parent.translation']);
    Page::setResolvedPageUrlSiteDomain($child, $site);

    expect(Page::hasPageHierarchy())->toBeTrue()
        ->and(Page::defaultOrdering())->toBe(PageOrderEnum::Default)
        ->and(Page::getSiteHomePage($site, $english)?->is($home))->toBeTrue()
        ->and(Page::getFirstPageByTypeForSite('article', $site, $english, fn ($query) => $query->whereKey($child->getKey()))?->is($child))->toBeTrue()
        ->and(Page::getDefaultType('default'))->toBeInstanceOf(Blueprint::class)
        ->and(Page::query()->publishedLatest()->pluck('id')->all())->toContain($child->getKey(), $parent->getKey())
        ->and(Page::query()->publishedDate()->pluck('id')->all())->not->toContain($pending->getKey(), $expired->getKey())
        ->and(Page::query()->pending()->pluck('id')->all())->toContain($pending->getKey())
        ->and(Page::query()->expired()->pluck('id')->all())->toContain($expired->getKey())
        ->and($child->getParentUrl($english))->toBe('/parent')
        ->and($child->getParentUrl($english, fullUrl: true))->toBe('https://example.test/parent')
        ->and($child->pageUrl->siteDomain->is($site->siteDomains->firstWhere('language_id', $english->getKey())))->toBeTrue()
        ->and($child->replicate()->uuid)->toBeNull()
        ->and($child->has_title_or_content)->toBeTrue()
        ->and($child->url_params)->toBe(['year' => 'int'])
        ->and($child->shouldLogVisit())->toBeTrue()
        ->and($child->getDraftKey())->toBe((string) $child->getKey())
        ->and($child->getPublishDate())->not->toBeNull()
        ->and($child->getPublishStatus())->toBe(PublishStatusEnum::published)
        ->and($child->isPending())->toBeFalse()
        ->and($child->isExpired())->toBeFalse()
        ->and($child->getSiblingsExcludingSelf()->contains($child))->toBeFalse()
        ->and($child->related()->pluck('id')->all())->toContain($parent->getKey())
        ->and($childUrl->fresh()->full_url)->toBe('https://example.test//parent/child')
        ->and(PageUrl::volatileUrls()->all())->toContain('/parent/child');

    $child->mergeMeta('seo.title', 'Nested title');
    $child->mergeMeta(['seo.description' => 'Nested description']);
    $child->loadParent($english);

    $resolvedWithoutExplicitLanguage = Page::getFirstPageByTypeForSite(
        'article',
        $site->fresh(['language']),
        modifyQueryUsing: fn ($query) => $query->whereKey($child->getKey()),
    );

    expect(Page::isBrokenWithoutPublish())->toBeFalse()
        ->and($resolvedWithoutExplicitLanguage?->is($child))->toBeTrue()
        ->and(Page::query()->notHomePage()->pluck('id')->all())->toContain($child->getKey())
        ->and(Page::query()->whereHasLanguage($english)->pluck('id')->all())->toContain($child->getKey())
        ->and($child->parent?->is($parent))->toBeTrue()
        ->and($child->meta['seo'])->toBe([
            'title' => 'Nested title',
            'description' => 'Nested description',
        ])
        ->and((new Page)->has_title_or_content)->toBeFalse();
});

it('exposes blueprint site theme translation and userstamp behavior through persisted models', function (): void {
    $userModel = config('auth.providers.users.model');
    $admin = $userModel::factory()->createOne();
    $english = Language::factory()->english()->createOne();
    $theme = Theme::factory()->createOne([
        'name' => 'Editorial',
        'key' => 'editorial',
        'meta' => [
            'colors' => ['primary' => '#ffffff', 'secondary' => 'rgb(0, 128, 64)', 'accent' => '#f00'],
            'dark_mode_toggle' => 'off',
            'header_position' => 'scroll_up',
            'secondary_containers' => ['aside', 'rail'],
        ],
        'admin' => ['icon' => 'heroicon-o-sparkles'],
    ]);
    $site = Site::factory()
        ->language($english)
        ->theme($theme)
        ->withTranslations($english, ['title' => 'Editorial site'], ['domain' => 'editorial.test', 'scheme' => 'https', 'path' => null, 'default' => true])
        ->createOne(['name' => 'Editorial site']);
    $site->load(['siteDomains.language', 'language', 'theme']);

    $blueprint = Blueprint::factory()->page()->createOne([
        'name' => 'Article',
        'group' => 'custom',
        'meta' => [
            'component' => '  article-card  ',
            'component_item' => '',
            'view_file' => ['invalid'],
            'livewire' => true,
            'content_structure' => ContentStructure::Html->value,
            'cache_time' => CacheTime::Daily->value,
        ],
    ]);

    $page = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($english, [
            'title' => 'Article title',
            'content' => '<p>This content becomes a summary.</p>',
        ], slug: 'article')
        ->createOne();

    $translation = expectPresent($page->translations()->first());
    $translation->forceFill([
        'meta' => [
            'slug' => 'article',
            'label' => 'Article label',
            'link_text' => '',
            'description' => 'Manual description',
            'keywords' => '',
            'title' => '',
        ],
    ])->save();

    test()->actingAs($admin);
    $stampedPage = Page::factory()->site($site)->type($blueprint)->createOne(['created_by' => null, 'updated_by' => null]);
    $stampedPage->update(['name' => 'Updated by admin']);
    $stampedPage->delete();
    $stampedPage->restore();

    Storage::fake('public');
    $signature = $theme->generatedImageSignature();
    $theme->forceFill([
        'admin' => [
            ...$theme->admin,
            'generated_image_signature' => $signature,
            'generated_image_status' => 'pending',
        ],
    ])->save();
    GenerateThemeImageAction::run($theme->getKey(), $signature);
    $freshTheme = expectPresent($theme->fresh());

    expect(Blueprint::getGroups())->toHaveKey('custom')
        ->and(Blueprint::getTypes())->toHaveKey('page')
        ->and($blueprint->content_structure)->toBe(ContentStructure::Html)
        ->and($blueprint->cache_time)->toBe(CacheTime::Daily)
        ->and($blueprint->component)->toBe('article-card')
        ->and($blueprint->component_item)->toBeNull()
        ->and($blueprint->view_file)->toBeNull()
        ->and($blueprint->is_livewire)->toBeTrue()
        ->and($blueprint->isSystem())->toBeFalse()
        ->and($site->getSiteDomainUrl($english))->toBe('https://editorial.test')
        ->and($site->getAllLanguages()->pluck('id')->all())->toBe([$english->getKey()])
        ->and($site->getThemeColor('primary'))->toBe('#ffffff')
        ->and($site->hasDefaultDomain())->toBeTrue()
        ->and(Site::totalSites())->toBeGreaterThanOrEqual(1)
        ->and(Site::getOptions()->all())->toHaveKey($site->getKey())
        ->and($theme->colors)->toBe(['primary' => '#ffffff', 'secondary' => 'rgb(0, 128, 64)', 'accent' => '#f00'])
        ->and($theme->dark_mode_toggle)->toBeFalse()
        ->and($theme->with_dark_mode)->toBeFalse()
        ->and($theme->scroll_up_header)->toBeTrue()
        ->and($theme->secondary_containers)->toBe(['aside', 'rail'])
        ->and($theme->colorsDifferFrom(['primary' => '#000000']))->toBeTrue()
        ->and($freshTheme->readyGeneratedImage())->not->toBeNull()
        ->and($translation->hasContent())->toBeTrue()
        ->and($translation->isPage())->toBeTrue()
        ->and($translation->isPageable())->toBeTrue()
        ->and($translation->label)->toBe('Article label')
        ->and($translation->link_text)->toBe('Article label')
        ->and($translation->slug)->toBe('article')
        ->and($translation->summary)->toContain('This content becomes a summary')
        ->and($translation->meta_description)->toBe('Manual description')
        ->and($translation->meta_keywords)->toBe('')
        ->and($translation->meta_title)->toBe('')
        ->and($stampedPage->created_by)->toBe($admin->getKey())
        ->and($stampedPage->updated_by)->toBe($admin->getKey())
        ->and($stampedPage->fresh()->deleted_by)->toBeNull()
        ->and($stampedPage->creator()->first()?->is($admin))->toBeTrue()
        ->and($stampedPage->editor()->first()?->is($admin))->toBeTrue();
});

it('does not lazy load page type metadata when url params are not hydrated', function (): void {
    $page = Page::factory()->make(['blueprint_id' => 123]);

    Model::preventLazyLoading();

    try {
        expect($page->url_params)->toBeNull();
    } finally {
        Model::preventLazyLoading(false);
    }
});
