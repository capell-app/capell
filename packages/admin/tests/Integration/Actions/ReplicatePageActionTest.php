<?php

declare(strict_types=1);

use Capell\Admin\Actions\ReplicatePageAction as AdminReplicatePageAction;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

it('replicates a page with relations', function (): void {
    $site = Site::factory()->createOne();
    Language::factory()->createOne();
    $page = Page::factory()->createOne([
        'site_id' => $site->id,
        'name' => 'Original',
    ]);

    /** @var Page $clone */
    $clone = AdminReplicatePageAction::run($page);

    expect($clone)->toBeInstanceOf(Page::class)
        ->and($clone->id)->not()->toBe($page->id)
        ->and($clone->name)->toContain('Original');
});

it('rolls back replicate page translations urls and registered related copies on a late collision', function (): void {
    $site = Site::factory()->createOne();
    $secondLanguage = Language::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id]);
    CapellCore::addCloneableRelations('page', 'layout');
    $counts = [Page::query()->count(), Translation::query()->count(), PageUrl::query()->count(), Layout::query()->count()];
    PageUrl::factory()->createOne([
        'site_id' => $site->id,
        'language_id' => $secondLanguage->id,
        'url' => '/reserved',
        'is_manual' => true,
        'status' => true,
        'pageable_type' => null,
        'pageable_id' => null,
    ]);
    $counts[2]++;

    expect(fn () => AdminReplicatePageAction::run($page, ['translations' => [
        ['language_id' => $site->language_id, 'title' => 'Available', 'meta' => ['slug' => 'available']],
        ['language_id' => $secondLanguage->id, 'title' => 'Reserved', 'meta' => ['slug' => 'reserved']],
    ]]))->toThrow(PageUrlCollisionException::class);

    expect([Page::query()->count(), Translation::query()->count(), PageUrl::query()->count(), Layout::query()->count()])->toBe($counts);
});

it('preserves the replicate action api and raw translation content with custom slugs', function (): void {
    $page = Page::factory()->createOne();
    $clone = AdminReplicatePageAction::run($page, [
        'name' => 'Custom name',
        'translations' => [[
            'language_id' => $page->site->language_id,
            'title' => 'Custom public title',
            'content' => '<p>Unchanged HTML</p>',
            'meta' => ['slug' => 'my-custom-slug'],
        ]],
    ]);
    $translation = $clone->translations->sole();

    expect($clone->name)->toBe('Custom name')
        ->and($clone->layout_id)->toBe($page->layout_id)
        ->and($translation->content)->toBe('<p>Unchanged HTML</p>')
        ->and($translation->title)->toBe('Custom public title')
        ->and($translation->slug)->toBe('my-custom-slug')
        ->and($clone->pageUrls->sole()->url)->toBe('/my-custom-slug');
});
