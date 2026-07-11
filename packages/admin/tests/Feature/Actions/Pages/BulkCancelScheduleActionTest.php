<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BulkCancelScheduleAction;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

test('clears future visible_from / visible_until and preserves past values', function (): void {
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

    expect($pendingPublish->fresh()->visible_from)->toBeNull();
    expect($pendingUnpublish->fresh()->visible_until)->toBeNull();
    expect($pendingUnpublish->fresh()->visible_from?->isPast())->toBeTrue();
    expect($noSchedule->fresh()->visible_from?->isPast())->toBeTrue();
});
