<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteReplicatedAction;
use Capell\Core\Events\SiteReplicated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Event;

it('replicates the site with a new id', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->state(['name' => 'Original'])->create();

    $clone = SiteReplicatedAction::run($site, []);

    expect($clone)->toBeInstanceOf(Site::class)
        ->and($clone->id)->not()->toBe($site->id)
        ->and($clone->name)->toContain('Original');
});

it('replicates pages when copy_pages is true and exposes them via the event', function (): void {
    Event::fake([SiteReplicated::class]);

    $site = Site::factory()->createOne();
    $page = Page::factory()->site($site)->withTranslations()->create();

    $clone = SiteReplicatedAction::run($site, ['copy_pages' => true]);

    expect($clone->pages()->count())->toBe(1);

    Event::assertDispatched(
        SiteReplicated::class,
        fn (SiteReplicated $event): bool => $event->source->is($site)
            && $event->replica->is($clone)
            && array_key_exists($page->id, $event->replacementPages),
    );
});

it('replicates domains when provided', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->create();

    $clone = SiteReplicatedAction::run($site, [
        'site_domains' => [
            ['language_id' => $language->id, 'url' => 'https://example.com/path'],
        ],
    ]);

    $domain = $clone->siteDomains()->first();
    expect($domain?->domain)->toBe('example.com')
        ->and($domain?->scheme)->toBe('https')
        ->and($domain?->path)->toBe('/path');
});

it('honors explicit languages selection for translations', function (): void {
    $languages = Language::factory()->count(3)->create();
    $site = Site::factory()->language($languages[0])->withTranslations($languages)->create();

    $clone = SiteReplicatedAction::run($site, [
        'language_id' => $languages[0]->id,
        'languages' => [$languages[1]->id],
    ]);

    $expected = collect([$languages[0]->id, $languages[1]->id])->sort()->values()->all();
    $actual = $clone->translations->pluck('language_id')->sort()->values()->all();

    expect($actual)->toEqualCanonicalizing($expected);
});

it('replicates nested page trees with selected language translations and urls', function (): void {
    Event::fake([SiteReplicated::class]);

    $english = Language::factory()->english()->create();
    $french = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->create([
        'name' => 'Original site',
        'default' => true,
    ]);

    $parent = Page::factory()
        ->site($site)
        ->withTranslations($english, ['title' => 'Parent page'])
        ->create(['name' => 'Parent']);
    $child = Page::factory()
        ->site($site)
        ->parent($parent)
        ->withTranslations($english, ['title' => 'Child page'])
        ->create(['name' => 'Child']);

    PageUrl::factory()
        ->site($site)
        ->page($parent)
        ->language($english)
        ->create(['url' => '/parent']);
    PageUrl::factory()
        ->site($site)
        ->page($child)
        ->language($english)
        ->create(['url' => '/parent/child']);

    $clone = SiteReplicatedAction::run($site, [
        'name' => 'Replicated site',
        'language_id' => $english->id,
        'languages' => [$french->id],
        'copy_pages' => true,
        'site_domains' => [
            ['language_id' => $english->id, 'url' => 'https://replica.test'],
            ['language_id' => $french->id, 'url' => 'https://replica.test/fr'],
        ],
    ]);

    $replicatedParent = Page::query()
        ->where('site_id', $clone->getKey())
        ->where('name', 'Parent')
        ->firstOrFail();
    $replicatedChild = Page::query()
        ->where('site_id', $clone->getKey())
        ->where('name', 'Child')
        ->firstOrFail();

    expect($clone->name)->toBe('Replicated site')
        ->and($clone->default)->toBeFalse()
        ->and($clone->siteDomains()->count())->toBe(2)
        ->and($replicatedChild->parent_id)->toBe($replicatedParent->getKey())
        ->and($replicatedParent->translations()->pluck('language_id')->sort()->values()->all())->toBe([
            $english->id,
            $french->id,
        ])
        ->and($replicatedParent->pageUrls()->pluck('language_id')->unique()->sort()->values()->all())->toBe([
            $english->id,
            $french->id,
        ])
        ->and($replicatedChild->pageUrls()->pluck('language_id')->unique()->sort()->values()->all())->toBe([
            $english->id,
            $french->id,
        ]);

    Event::assertDispatched(
        SiteReplicated::class,
        fn (SiteReplicated $event): bool => $event->replacementPages[$parent->id]->is($replicatedParent)
            && $event->replacementPages[$child->id]->is($replicatedChild),
    );
});

it('delegates default page setup when copying pages is not requested', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $calls = [];

    app()->instance('capell.admin.create-default-pages-action', function (Site $site, array $pages) use (&$calls): void {
        $calls[] = [
            'site_id' => $site->getKey(),
            'pages' => $pages,
        ];
    });

    $clone = SiteReplicatedAction::run($site, [
        'setup_pages' => true,
        'auto_create_pages' => ['home', 'contact'],
    ]);

    expect($calls)->toBe([
        [
            'site_id' => $clone->getKey(),
            'pages' => ['home', 'contact'],
        ],
    ]);
});
