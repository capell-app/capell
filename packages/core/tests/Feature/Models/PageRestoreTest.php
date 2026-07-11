<?php

declare(strict_types=1);

use Capell\Core\Exceptions\PageRestoreSlugConflictException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

it('restores page urls and translations with the page', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->withTranslations()->createOne(['language_id' => $language->getKey()]);
    $page = Page::factory()->site($site)->createOne();

    $translation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->createOne(['title' => 'About Capell']);

    $pageUrl = PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->createOne(['url' => '/about']);

    $page->delete();

    expect($page->fresh()->trashed())->toBeTrue()
        ->and($pageUrl->fresh()->trashed())->toBeTrue()
        ->and($translation->fresh()->trashed())->toBeTrue();

    Page::query()->withTrashed()->whereKey($page->getKey())->firstOrFail()->restore();

    expect($page->fresh()->trashed())->toBeFalse()
        ->and($pageUrl->fresh()->trashed())->toBeFalse()
        ->and($translation->fresh()->trashed())->toBeFalse();
});

it('blocks restoring a page when a live page owns one of its old urls', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->withTranslations()->createOne(['language_id' => $language->getKey()]);
    $deletedPage = Page::factory()->site($site)->createOne(['name' => 'Deleted About']);
    $livePage = Page::factory()->site($site)->createOne(['name' => 'Live About']);

    PageUrl::factory()
        ->page($deletedPage)
        ->site($site)
        ->language($language)
        ->createOne(['url' => '/about']);

    $deletedPage->delete();

    PageUrl::factory()
        ->page($livePage)
        ->site($site)
        ->language($language)
        ->createOne(['url' => '/about']);

    expect(fn (): bool => Page::query()->withTrashed()->whereKey($deletedPage->getKey())->firstOrFail()->restore())
        ->toThrow(PageRestoreSlugConflictException::class);

    expect($deletedPage->fresh()->trashed())->toBeTrue();
});

it('restores trashed ancestors before restoring a child page', function (): void {
    $parent = Page::factory()->createOne(['name' => 'Parent']);
    $child = Page::factory()
        ->parent($parent)
        ->createOne([
            'name' => 'Child',
        ]);

    $child->delete();

    $parent->delete();

    expect($parent->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->trashed())->toBeTrue();

    Page::query()->withTrashed()->whereKey($child->getKey())->firstOrFail()->restore();

    expect($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->parent_id)->toBe($parent->getKey());
});
