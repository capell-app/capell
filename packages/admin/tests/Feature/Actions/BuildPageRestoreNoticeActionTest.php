<?php

declare(strict_types=1);

use Capell\Admin\Actions\BuildPageRestoreNoticeAction;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class);

it('escapes deleted page names in the restore notice and still names ordinary pages', function (): void {
    test()->actingAsAdmin();

    $parent = Page::factory()->createOne(['name' => 'Parent']);
    $hostile = Page::factory()->parent($parent)->createOne(['name' => '<img src=x onerror=alert(1)>']);
    $ordinary = Page::factory()->parent($parent)->createOne(['name' => 'Ordinary child']);
    Page::query()->whereKey([$parent->id, $hostile->id, $ordinary->id])->update(['deleted_at' => now()]);

    $notice = BuildPageRestoreNoticeAction::run($parent->fresh(), [$parent->id]);

    expect($notice)->toBeString()
        ->toContain('Ordinary child')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->not->toContain('<img');
});
