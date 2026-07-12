<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\TestingFrontend;

uses(TestingFrontend::class);

test('page with neighbors', function (): void {
    $site = Site::factory()->withTranslations()->create();

    $parent = Page::factory()->site($site)->withTranslations()->create();
    $type = Blueprint::factory()->page()->meta(['with_next_prev' => true, 'with_author' => true])->create();

    $pages = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->parent($parent)
        ->withTranslations()
        ->forEachSequence(
            ['visible_from' => now()->subDays(5)],
            ['visible_from' => now()->subDays(3)],
            ['visible_from' => now()->subDays(1)],
        )
        ->create();

    $page = $pages->get(1);

    expect($page)
        ->getSiblingsExcludingSelf()->toHaveCount(2)
        ->and($page->getPrevSibling())->toBeInstanceOf(Page::class)->id->toEqual($pages->get(0)->id)
        ->and($page->getNextSibling())->toBeInstanceOf(Page::class)->id->toEqual($pages->get(2)->id);
});
