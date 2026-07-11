<?php

declare(strict_types=1);

// tests/Integration/Models/SiteTest.php

use Capell\Core\Contracts\Media\MediaContract as Media;
use Capell\Core\Database\Factories\MediaFactory;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;

it('belongs to a type', function (): void {
    $type = Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Site]);
    $site = Site::factory()->createOne(['blueprint_id' => $type->id]);

    expect($site->type)->toBeInstanceOf(Blueprint::class)
        ->and($site->type->id)->toBe($type->id);
});

it('falls back to type meta when site meta does not contain the requested key', function (): void {
    $type = Blueprint::factory()
        ->site()
        ->create([
            'meta' => [
                'seo' => [
                    'title' => 'Blueprint title',
                ],
            ],
        ]);

    $site = Site::factory()->createOne([
        'blueprint_id' => $type->id,
        'meta' => [],
    ]);

    expect($site->getMeta('seo.title'))->toBe('Blueprint title');
});

it('belongs to a theme', function (): void {
    $theme = Theme::factory()->createOne();
    $site = Site::factory()->createOne(['theme_id' => $theme->id]);

    expect($site->theme)->toBeInstanceOf(Theme::class)
        ->and($site->theme->id)->toBe($theme->id);
});

it('belongs to a language', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne(['language_id' => $language->id]);

    expect($site->language)->toBeInstanceOf(Language::class)
        ->and($site->language->id)->toBe($language->id);
});

it('has many pages', function (): void {
    $site = Site::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id]);

    expect($site->pages->pluck('id'))->toContain($page->id);
});

it('has many site domains', function (): void {
    $site = Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne(['site_id' => $site->id]);

    expect($site->siteDomains->pluck('id'))->toContain($siteDomain->id);
});

it('has one site domain', function (): void {
    $site = Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne(['site_id' => $site->id]);

    expect($site->siteDomain)->toBeInstanceOf(SiteDomain::class)
        ->and($site->siteDomain->id)->toBe($siteDomain->id);
});

it('has many translations', function (): void {
    $site = Site::factory()->createOne();
    $translation = Translation::factory()->createOne(['translatable_id' => $site->id, 'translatable_type' => 'site']);

    expect($site->translations->pluck('id'))->toContain($translation->id);
});

it('has one translation', function (): void {
    $site = Site::factory()->createOne();
    $translation = Translation::factory()->createOne(['translatable_id' => $site->id, 'translatable_type' => 'site']);

    expect($site->translation)->toBeInstanceOf(Translation::class)
        ->and($site->translation->id)->toBe($translation->id);
});

it('has many layouts', function (): void {
    $site = Site::factory()->createOne();
    $layout = Layout::factory()->createOne(['site_id' => $site->id]);

    expect($site->layouts->pluck('id'))->toContain($layout->id);
});

it('belongs to an image', function (): void {
    $site = Site::factory()->createOne();

    $media = MediaFactory::new([
        'model_type' => resolve(Site::class)->getMorphClass(),
        'model_id' => $site->id,
        'collection_name' => MediaCollectionEnum::Image,
    ])
        ->createOne();

    expect($site->image)->toBeInstanceOf(Media::class)
        ->and($site->image->id)->toBe($media->id);
});

it('belongs to a logo', function (): void {
    $site = Site::factory()->createOne();

    $media = MediaFactory::new([
        'model_type' => resolve(Site::class)->getMorphClass(),
        'model_id' => $site->id,
        'collection_name' => MediaCollectionEnum::Logo,
    ])
        ->createOne();

    expect($site->logo)->toBeInstanceOf(Media::class)
        ->and($site->logo->id)->toBe($media->id);
});

it('belongs to a logo inverted', function (): void {
    $site = Site::factory()->createOne();

    $media = MediaFactory::new([
        'model_type' => resolve(Site::class)->getMorphClass(),
        'model_id' => $site->id,
        'collection_name' => MediaCollectionEnum::LogoInverted,
    ])
        ->createOne();

    expect($site->logoInverted)->toBeInstanceOf(Media::class)
        ->and($site->logoInverted->id)->toBe($media->id);
});

it('can scope sorted', function (): void {
    Site::factory()->createOne(['name' => 'B', 'default' => false, 'order' => 2]);
    Site::factory()->createOne(['name' => 'A', 'default' => true, 'order' => 1]);
    Site::factory()->createOne(['name' => 'C', 'default' => false, 'order' => 3]);

    $result = Site::query()->ordered()->pluck('name')->all();

    expect($result)->toBe(['A', 'B', 'C']);
});

it('has a default domain', function (): void {
    $site = Site::factory()
        ->has(SiteDomain::factory()->default())
        ->create();

    expect($site->hasDefaultDomain())->toBeTrue();
});

it('returns all unique languages for a site', function (): void {
    $siteLanguage = Language::factory()->createOne(['code' => 'en']);
    $otherLanguage = Language::factory()->createOne(['code' => 'fr']);
    $site = Site::factory()->createOne(['language_id' => $siteLanguage->id]);

    SiteDomain::factory()->createOne(['site_id' => $site->id, 'language_id' => $otherLanguage->id]);

    $languages = $site->getAllLanguages();
    expect($languages)->toHaveCount(2);
    expect($languages[0]->id)->toBe($siteLanguage->id);
    expect($languages[1]->id)->toBe($otherLanguage->id);
});
