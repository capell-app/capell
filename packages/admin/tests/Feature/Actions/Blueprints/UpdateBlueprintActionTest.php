<?php

declare(strict_types=1);

use Capell\Admin\Actions\Blueprints\UpdateBlueprintAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Models\Blueprint;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('type');

beforeEach(function (): void {
    Permission::findOrCreate(CapellPermission::ManagePageRestrictions->name());
});

it('updates a blueprint without changing restrictions for an unauthorized actor', function (): void {
    $existingRole = Role::findOrCreate('existing_editor');
    $submittedRole = Role::findOrCreate('submitted_editor');
    $blueprint = Blueprint::factory()->page()->createOne();
    $blueprint->syncRoleRestrictions([$existingRole->getKey()]);

    test()->actingAsUser();

    $updated = UpdateBlueprintAction::run($blueprint, [
        'name' => 'Updated page blueprint',
        'admin' => [
            ...$blueprint->admin,
            'role_restrictions' => [$submittedRole->getKey()],
        ],
    ]);

    expect($updated->refresh())
        ->name->toBe('Updated page blueprint')
        ->and($updated->admin)->not->toHaveKey('role_restrictions')
        ->and($updated->getRestrictedRoleIds()->all())->toBe([$existingRole->getKey()]);
});

it('updates a blueprint and syncs restrictions for an authorized actor', function (): void {
    $existingRole = Role::findOrCreate('existing_editor');
    $submittedRole = Role::findOrCreate('submitted_editor');
    $blueprint = Blueprint::factory()->page()->createOne();
    $blueprint->syncRoleRestrictions([$existingRole->getKey()]);

    $user = test()->createUserWithPermission(CapellPermission::ManagePageRestrictions->name());
    test()->actingAs($user);

    $updated = UpdateBlueprintAction::run($blueprint, [
        'name' => 'Restricted page blueprint',
        'admin' => [
            ...$blueprint->admin,
            'role_restrictions' => [$submittedRole->getKey()],
        ],
    ]);

    expect($updated->refresh())
        ->name->toBe('Restricted page blueprint')
        ->and($updated->admin)->not->toHaveKey('role_restrictions')
        ->and($updated->getRestrictedRoleIds()->all())->toBe([$submittedRole->getKey()]);
});
