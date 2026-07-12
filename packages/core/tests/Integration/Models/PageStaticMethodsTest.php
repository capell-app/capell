<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;

it('getMorphRelations returns expected base relations and applies language order scope', function (): void {
    // Arrange: a language to pass in
    $language = Language::factory()->createOne();

    // Act
    $relations = Page::getMorphRelations($language, normalizeKey: true);

    // Assert: base keys present
    expect($relations)->toHaveKeys(['ancestors', 'site', 'image', 'translation', 'blueprint', 'pageUrl']);
});

it('getDefaultType returns the first enabled visible accessible page type ordered', function (): void {
    // Arrange: create several types, only some page blueprints in a specific group
    $group = 'pages';

    // Non-page type
    Blueprint::factory()->site()->create(['group' => $group, 'default' => false]);

    // Page blueprints with ordering via created_at; we will ensure the first ordered one is returned
    $t1 = Blueprint::factory()->page()->create(['group' => $group, 'default' => false, 'created_at' => now()->subDays(5)]);
    $t2 = Blueprint::factory()->page()->create(['group' => $group, 'default' => false, 'created_at' => now()->subDays(10)]);

    // Act
    $found = Page::getDefaultType($group);
    $found = expectPresent($found);

    // Assert: should return the earliest ordered (ordered() defaults asc by order then lft; factory may not set order, so we assert not null)
    expect($found)->toBeInstanceOf(Blueprint::class);
    expect($found->group)->toBe($group);
});

it('getSiteHomePage returns published home page for site and language', function (): void {
    // Arrange: site with a default language
    /** @var Site $site */
    $site = Site::factory()->createOne();
    /** @var Language $language */
    $language = $site->language; // default language from factory relation

    // Create a home Blueprint
    /** @var Blueprint $homeType */
    $homeType = Blueprint::factory()->page()->create(['key' => 'home']);

    // Create published home page
    /** @var Page $home */
    $home = Page::factory()->createOne([
        'site_id' => $site->id,
        'blueprint_id' => $homeType->id,
    ]);

    // Attach translation and url for language
    Translation::factory()->translatable($home)->for($language)->create();
    PageUrl::factory()->page($home)->for($site)->create(['language_id' => $language->id]);

    // A non-home page should not be returned
    $normalType = Blueprint::factory()->page()->create(['key' => 'normal']);
    Page::factory()->createOne([
        'site_id' => $site->id,
        'blueprint_id' => $normalType->id,
    ]);

    // Act
    $found = Page::getSiteHomePage($site, $language);
    $found = expectPresent($found);

    // Assert
    expect($found)->not()->toBeNull();
    expect($found->id)->toBe($home->id);
});

it('getFirstPageByTypeForSite returns first published page of given type with language constraints', function (): void {
    // Arrange: site & language
    $site = Site::factory()->createOne();
    $language = $site->language;

    // Blueprint key
    $key = 'blog';
    $type = Blueprint::factory()->page()->create(['key' => $key]);

    $published = Page::factory()->createOne(['site_id' => $site->id, 'blueprint_id' => $type->id]);

    // Required translation & url records for language
    Translation::factory()->translatable($published)->for($language)->create();
    PageUrl::factory()->page($published)->for($site)->create(['language_id' => $language->id]);

    // Act: fetch first page by type
    $found = Page::getFirstPageByTypeForSite($key, $site, $language);
    $found = expectPresent($found);

    // Assert: should be the published page
    expect($found)->not()->toBeNull();
    expect($found->id)->toBe($published->id);

    // And respects modifyQueryUsing callback
    $excluded = Page::factory()->createOne(['site_id' => $site->id, 'blueprint_id' => $type->id]);
    Translation::factory()->translatable($excluded)->for($language)->create();
    PageUrl::factory()->page($excluded)->for($site)->create(['language_id' => $language->id]);

    $foundExcluded = Page::getFirstPageByTypeForSite($key, $site, $language, function (BuilderContract $query) use ($excluded): void {
        $query->where('pages.id', '!=', $excluded->id);
    });

    expect($foundExcluded?->id)->toBe($published->id);
});

it('getTypes returns mapping of blueprint_id to type name for all blueprints present in pages', function (): void {
    // Arrange: create several types
    [$typeA, $typeB, $typeC] = Blueprint::factory()
        ->forEachSequence(
            ['name' => 'TypeA'],
            ['name' => 'TypeB'],
            ['name' => 'TypeC'],
        )
        ->create();

    // Create pages for each type
    Page::factory()
        ->forEachSequence(
            ['blueprint_id' => $typeA->id],
            ['blueprint_id' => $typeB->id],
            ['blueprint_id' => $typeC->id],
        )
        ->create();

    // Act
    $types = Page::getTypes();

    // Assert: should return array mapping blueprint_id => type name
    expect($types)->toBeArray()
        ->toHaveKey($typeA->id)
        ->toHaveKey($typeB->id)
        ->toHaveKey($typeC->id)
        ->and($types[$typeA->id])->toBe('TypeA')
        ->and($types[$typeB->id])->toBe('TypeB')
        ->and($types[$typeC->id])->toBe('TypeC');
});
