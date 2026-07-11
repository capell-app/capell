<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\RenderPageRecordDataAction;

it('renders record data for a given page', function (): void {
    $site = Site::factory()->withTranslations()->create();
    /** @var Page $page */
    $page = Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();
    $translation = expectPresent($page->translation);

    $translation->title = 'Hello :name';
    $translation->content = 'Welcome :name';
    $translation->meta = ['title' => 'Meta :name'];

    RenderPageRecordDataAction::run($page, ['name' => 'World']);
    $meta = $translation->meta ?? [];

    expect($translation->title)->toBe('Hello World')
        ->and($translation->content)->toBe('Welcome World')
        ->and($meta['title'] ?? null)->toBe('Meta World');
});

it('skips non string meta values when rendering record data', function (): void {
    $site = Site::factory()->withTranslations()->create();
    /** @var Page $page */
    $page = Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();
    $translation = expectPresent($page->translation);

    $translation->meta = [
        'title' => ['nested' => true],
        'description' => (object) ['nested' => true],
        'keywords' => 'Keywords :name',
        'label' => '',
    ];

    RenderPageRecordDataAction::run($page, ['name' => 'World']);
    $meta = $translation->meta ?? [];

    expect($meta['title'] ?? null)->toBe(['nested' => true])
        ->and($meta['description'] ?? null)->toBe(['nested' => true])
        ->and($meta['keywords'] ?? null)->toBe('Keywords World')
        ->and($meta['label'] ?? null)->toBe('');
});
