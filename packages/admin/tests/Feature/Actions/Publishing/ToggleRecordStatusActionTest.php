<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\Publishing\ToggleRecordStatusAction;
use Capell\Core\Models\Layout;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('toggles a statusable record from active to inactive', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $layout = Layout::factory()->create(['status' => true]);

    $result = ToggleRecordStatusAction::run($layout, $actor);

    expect($result->changed)->toBeTrue()
        ->and($layout->fresh()->isEnabled())->toBeFalse();
});

it('activates an inactive statusable record', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $layout = Layout::factory()->create(['status' => false]);

    $result = ToggleRecordStatusAction::run($layout, $actor, enabled: true);

    expect($result->changed)->toBeTrue()
        ->and($layout->fresh()->isEnabled())->toBeTrue();
});

it('skips when the target status already matches', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $layout = Layout::factory()->create(['status' => true]);

    $result = ToggleRecordStatusAction::run($layout, $actor, enabled: true);

    expect($result->skipped)->toBeTrue()
        ->and($result->reason)->toBe('unchanged');
});

it('is authorization gated', function (): void {
    Permission::findOrCreate(FilamentShield::defaultPermissionKeyBuilder(
        affix: 'update',
        separator: Utils::getConfig()->permissions->separator,
        subject: 'Layout',
        case: Utils::getConfig()->permissions->case,
    ));

    $actor = test()->createUser();
    $layout = Layout::factory()->create(['status' => true]);

    $result = ToggleRecordStatusAction::run($layout, $actor);

    expect($result->skipped)->toBeTrue()
        ->and($result->reason)->toBe('unauthorized')
        ->and($layout->fresh()->isEnabled())->toBeTrue();
});
