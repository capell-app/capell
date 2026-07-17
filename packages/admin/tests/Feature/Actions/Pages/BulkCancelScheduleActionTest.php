<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BulkCancelScheduleAction;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('reverts a pending publish to draft, clears future expiry, and preserves past values', function (): void {
    Permission::query()->firstOrCreate(['name' => 'Update:Page', 'guard_name' => 'web']);
    $pendingPublish = Page::factory()->createOne([
        'visible_from' => now()->addWeek(),
        'visible_until' => null,
    ]);
    $pendingUnpublish = Page::factory()->createOne([
        'visible_from' => now()->subMonth(),
        'visible_until' => now()->addWeek(),
    ]);
    $noSchedule = Page::factory()->createOne([
        'visible_from' => now()->subYear(),
        'visible_until' => null,
    ]);

    $actor = test()->createUserWithPermission('Update:Page');

    $result = BulkCancelScheduleAction::run(
        Collection::make([$pendingPublish, $pendingUnpublish, $noSchedule]),
        $actor,
    );

    expect($result['cancelled'])->toBe(2)
        ->and($result['skipped'])->toBe(1);

    // Cancelling a pending publish must park the page in draft, NOT make it live.
    expect($pendingPublish->fresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft);
    expect($pendingUnpublish->fresh()->visible_until)->toBeNull();
    expect($pendingUnpublish->fresh()->visible_from?->isPast())->toBeTrue();
    expect($noSchedule->fresh()->visible_from?->isPast())->toBeTrue();
});

it('skips a page the actor cannot update', function (): void {
    Permission::query()->firstOrCreate(['name' => 'Update:Page', 'guard_name' => 'web']);
    $scheduled = Page::factory()->createOne([
        'visible_from' => now()->addWeek(),
        'visible_until' => null,
    ]);

    Permission::query()->firstOrCreate(['name' => 'View:Page', 'guard_name' => 'web']);
    $actorWithoutPermission = test()->createUserWithPermission('View:Page');

    $result = BulkCancelScheduleAction::run(Collection::make([$scheduled]), $actorWithoutPermission);

    expect($result['cancelled'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($scheduled->fresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::scheduled);
});
