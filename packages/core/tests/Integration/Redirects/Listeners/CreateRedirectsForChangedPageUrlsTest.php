<?php

declare(strict_types=1);

use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;

it('creates redirects for previous page urls carried by page saved form data', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();
    PageUrl::factory()
        ->page($page)
        ->site($page->site)
        ->language($language)
        ->state(['url' => '/new'])
        ->create();

    PageSavedAction::run($page, [
        '_previous_urls' => [
            $language->getKey() => '/old',
        ],
    ]);

    expect(PageUrl::query()->where('url', '/old')->first())
        ->not()->toBeNull()
        ->status_code->toBe(RedirectStatusCodeEnum::Permanent);
});
