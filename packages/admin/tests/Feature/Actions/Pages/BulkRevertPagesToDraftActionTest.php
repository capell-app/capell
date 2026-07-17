<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BulkRevertPagesToDraftAction;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('reverts a published page to draft and reports the existing return shape', function (): void {
    Permission::query()->firstOrCreate(['name' => 'Update:Page', 'guard_name' => 'web']);

    $publishedPage = Page::factory()->createOne([
        'visible_from' => now()->subMonth(),
        'visible_until' => null,
    ]);
    $alreadyDraftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(CarbonImmutable::now()),
        'visible_until' => null,
    ]);

    $draftVisibleFromBefore = $alreadyDraftPage->fresh()->visible_from;

    $actor = test()->createUserWithPermission('Update:Page');

    $result = BulkRevertPagesToDraftAction::run(
        Collection::make([$publishedPage, $alreadyDraftPage]),
        $actor,
    );

    expect($result['reverted'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and($result['skipped_pages'])->toBe([[
            'id' => $alreadyDraftPage->id,
            'name' => $alreadyDraftPage->name,
            'reason' => 'already_draft',
        ]]);

    expect($publishedPage->fresh()->publishVisibilityState())
        ->toBe(PublishVisibilityStateEnum::draft);

    // The already-draft page must be left genuinely untouched, not rewritten.
    expect($alreadyDraftPage->fresh()->visible_from?->toDateTimeString())
        ->toBe($draftVisibleFromBefore?->toDateTimeString());
});

it('skips a page the actor cannot update and leaves its dates untouched', function (): void {
    Permission::query()->firstOrCreate(['name' => 'Update:Page', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'View:Page', 'guard_name' => 'web']);

    $publishedPage = Page::factory()->createOne([
        'visible_from' => now()->subMonth(),
        'visible_until' => now()->addYear(),
    ]);

    $visibleFromBefore = $publishedPage->fresh()->visible_from;
    $visibleUntilBefore = $publishedPage->fresh()->visible_until;

    $actorWithoutPermission = test()->createUserWithPermission('View:Page');

    $result = BulkRevertPagesToDraftAction::run(
        Collection::make([$publishedPage]),
        $actorWithoutPermission,
    );

    expect($result['reverted'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['skipped_pages'])->toBe([[
            'id' => $publishedPage->id,
            'name' => $publishedPage->name,
            'reason' => 'unauthorized',
        ]]);

    $freshPage = $publishedPage->fresh();

    expect($freshPage->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and($freshPage->visible_from?->toDateTimeString())->toBe($visibleFromBefore?->toDateTimeString())
        ->and($freshPage->visible_until?->toDateTimeString())->toBe($visibleUntilBefore?->toDateTimeString());
});

it('maps a trashed page to the trashed skip reason', function (): void {
    Permission::query()->firstOrCreate(['name' => 'Update:Page', 'guard_name' => 'web']);

    $trashedPage = Page::factory()->createOne([
        'visible_from' => now()->subMonth(),
        'visible_until' => null,
    ]);
    $trashedPage->delete();

    $actor = test()->createUserWithPermission('Update:Page');

    $result = BulkRevertPagesToDraftAction::run(
        Collection::make([$trashedPage]),
        $actor,
    );

    expect($result['reverted'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['skipped_pages'])->toBe([[
            'id' => $trashedPage->id,
            'name' => $trashedPage->name,
            'reason' => 'trashed',
        ]]);
});
