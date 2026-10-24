<?php

declare(strict_types=1);

use Capell\Admin\Actions\Blueprints\SaveInlineBlueprintAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Models\Blueprint;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('refuses inline writes from a page editor and leaves the global blueprint intact', function (): void {
    $blueprint = Blueprint::factory()->page()->create();
    $original = $blueprint->fresh()->only(['name', 'key', 'type', 'status', 'admin', 'meta', 'updated_at']);
    $actor = User::factory()->create();
    foreach (['Create:Page', 'Update:Page'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    test()->actingAs($actor);
    $count = Blueprint::query()->count();

    expect(fn (): Blueprint => SaveInlineBlueprintAction::run($blueprint, ['status' => false]))->toThrow(AuthorizationException::class);
    expect(fn (): Blueprint => SaveInlineBlueprintAction::run(null, ['name' => 'Injected', 'type' => 'page']))->toThrow(AuthorizationException::class);
    expect($blueprint->fresh()->only(array_keys($original)))->toEqual($original)
        ->and(Blueprint::query()->count())->toBe($count);
});

it('preserves restrictions and unsubmitted metadata when a blueprint editor lacks restriction permission', function (): void {
    $existing = Role::findOrCreate('restricted-existing', 'web');
    $submitted = Role::findOrCreate('restricted-submitted', 'web');
    $blueprint = Blueprint::factory()->page()->create(['admin' => ['icon' => 'existing-icon']]);
    $blueprint->syncRoleRestrictions([$existing->id]);

    $actor = User::factory()->create();
    $actor->givePermissionTo(Permission::findOrCreate('Update:Blueprint', 'web'));

    test()->actingAs($actor);

    SaveInlineBlueprintAction::run($blueprint, ['name' => 'Ignored', 'admin' => ['notes' => 'Updated notes', 'role_restrictions' => [$submitted->id]]]);

    expect($blueprint->fresh()->name)->not->toBe('Ignored')
        ->and($blueprint->fresh()->admin)->toMatchArray(['icon' => 'existing-icon', 'notes' => 'Updated notes'])
        ->not->toHaveKey('role_restrictions')
        ->and($blueprint->getRestrictedRoleIds()->all())->toBe([$existing->id]);
});

it('creates inline blueprints without injected restrictions and permits authorised restriction changes', function (): void {
    $role = Role::findOrCreate('inline-restricted', 'web');
    $actor = User::factory()->create();
    foreach (['Create:Blueprint', 'Update:Blueprint'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    test()->actingAs($actor);

    $blueprint = SaveInlineBlueprintAction::run(null, ['name' => 'Inline', 'type' => 'page', 'admin' => ['role_restrictions' => [$role->id]]]);
    expect($blueprint->getRestrictedRoleIds()->all())->toBe([]);

    $actor->givePermissionTo(Permission::findOrCreate(CapellPermission::ManagePageRestrictions->name(), 'web'));
    SaveInlineBlueprintAction::run($blueprint, ['admin' => ['role_restrictions' => [$role->id]]]);
    expect($blueprint->getRestrictedRoleIds()->all())->toBe([$role->id]);
    SaveInlineBlueprintAction::run($blueprint, ['admin' => ['role_restrictions' => []]]);
    expect($blueprint->getRestrictedRoleIds()->all())->toBe([]);
});
