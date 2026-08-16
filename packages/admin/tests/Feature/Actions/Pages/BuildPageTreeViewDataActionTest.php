<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BuildPageTreeViewDataAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

it('builds page tree data with ordered ancestors and related branches', function (): void {
    $site = Site::factory()->withTranslations()->create();
    Page::factory()->recycle($site)->home()->published()->withTranslations()->create();
    $ancestor = Page::factory()->recycle($site)->withTranslations()->create();
    $record = Page::factory()->recycle($site)->parent($ancestor)->withTranslations()->create();
    Page::factory()->recycle($site)->parent($ancestor)->withTranslations()->create();
    $child = Page::factory()->recycle($site)->parent($record)->withTranslations()->create();

    $data = BuildPageTreeViewDataAction::run($record->refresh());

    expect($data['record']->is($record))->toBeTrue()
        ->and($data['home'])->toBeInstanceOf(Page::class)
        ->and($data['ancestors']->pluck('id')->all())->toBe([$ancestor->getKey()])
        ->and($data['siblings']->pluck('id'))->toContain($record->getKey())
        ->and($data['children']->pluck('id')->all())->toBe([$child->getKey()])
        ->and($data['resourceClass'])->toBeString()
        ->and($data['resourceIcon'])->not->toBeNull();
});

it('returns empty ancestor data when the record is a root without a home page', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $record = Page::factory()->recycle($site)->withTranslations()->create();

    $data = BuildPageTreeViewDataAction::run($record);

    expect($data['home'])->toBeNull()
        ->and($data['ancestors'])->toBeEmpty()
        ->and($data['children'])->toBeEmpty();
});
