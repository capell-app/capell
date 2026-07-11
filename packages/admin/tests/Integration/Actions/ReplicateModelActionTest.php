<?php

declare(strict_types=1);

use Capell\Admin\Actions\ReplicateModelAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

it('replicates a generic model', function (): void {
    $site = Site::factory()->createOne();
    $lang = Language::factory()->createOne();
    $page = Page::factory()->createOne([
        'site_id' => $site->id,
    ]);

    $clone = ReplicateModelAction::run($page);

    expect($clone)->toBeInstanceOf(Page::class)
        ->and($clone->getKey())->not()->toBe($page->getKey());
});
