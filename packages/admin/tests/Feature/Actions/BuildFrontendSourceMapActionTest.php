<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BuildFrontendSourceMapAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;

it('builds a frontend source map for page translations and layouts', function (): void {
    $language = Language::factory()->english()->create();
    $layout = Layout::factory()->createOne([
        'containers' => [
            'main' => [
                'elements' => [
                    ['element_key' => 'hero', 'occurrence' => 1],
                ],
            ],
        ],
    ]);
    $page = Page::factory()
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Homepage Bulldog'])
        ->create();

    $itemsByType = BuildFrontendSourceMapAction::run($page)
        ->keyBy('typeLabel')
        ->all();

    expect($itemsByType)->toHaveKeys([
        'Page',
        'Page translation',
        'Layout',
    ])
        ->and($itemsByType['Page translation']->preview)->toBe('Homepage Bulldog')
        ->and($itemsByType['Layout']->preview)->toBe($layout->name)
        ->and($itemsByType['Layout']->fieldPath)->toBe('pages.layout_id');
});

it('includes translation content and seo metadata previews', function (): void {
    $page = Page::factory()->createOne();

    Translation::factory()
        ->translatable($page)
        ->create([
            'title' => 'Source mapped page',
            'content' => '<p>Source mapped content</p>',
            'meta' => ['meta_title' => 'Source mapped SEO title'],
        ]);

    $itemsByType = BuildFrontendSourceMapAction::run($page)
        ->keyBy('typeLabel')
        ->all();

    expect($itemsByType)->toHaveKeys([
        'Page translation',
        'Page content',
        'SEO title',
    ])
        ->and($itemsByType['Page content']->preview)->toBe('Source mapped content')
        ->and($itemsByType['SEO title']->preview)->toBe('Source mapped SEO title');
});
