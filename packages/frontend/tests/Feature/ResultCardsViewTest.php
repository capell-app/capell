<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Support\State\FrontendState;

it('renders frontend results as grid result cards with read more links', function (): void {
    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations($language)
        ->create();

    $page = Page::factory()
        ->site($site)
        ->withTranslations($language, [
            'title' => 'Result Card Title',
            'summary' => 'Short result card summary.',
            'meta' => ['slug' => 'result-card-title'],
        ])
        ->create();

    $pageUrl = $page->pageUrls()
        ->where('language_id', $language->id)
        ->where('url', '/result-card-title')
        ->firstOrFail();

    $page->load('translation');
    $page->setRelation('pageUrl', $pageUrl);

    resolve(FrontendState::class)
        ->withLanguage($language)
        ->withSite($site)
        ->withTheme($theme)
        ->withPage($page);

    $view = $this->view('capell::components.page.results', [
        'results' => collect([$page]),
    ]);

    $view
        ->assertSee('role="list"', false)
        ->assertSee('role="listitem"', false)
        ->assertSee(__('capell-frontend::generic.read_more'))
        ->assertSee(__('capell-frontend::generic.read_more_about', ['title' => 'Result Card Title']))
        ->assertSee('/result-card-title', false)
        ->assertSee('Result Card Title');
});
