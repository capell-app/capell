<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Policies\RolePolicy;
use Capell\Core\Models\BlockTemplate;
use Capell\Tests\Fixtures\Models\User;
use Filament\Facades\Filament;

use function Filament\get_authorization_response;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('refuses resource access until its Shield permission is granted through a role', function (string $resource, string $model): void {
    $actor = User::factory()->createOne();
    $role = Role::findOrCreate('restricted-editor', 'web');
    $actor->assignRole($role);
    test()->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(Gate::getPolicyFor($model))->not->toBeNull();
    expect($resource::canViewAny())->toBeFalse();
    expect($resource::canCreate())->toBeFalse();

    $permissions = Utils::getConfig()->permissions;
    $name = FilamentShield::defaultPermissionKeyBuilder(affix: 'view_any', separator: $permissions->separator, subject: class_basename($model), case: $permissions->case);
    $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
    $actor->unsetRelation('roles');
    expect($resource::canViewAny())->toBeTrue()
        ->and($resource::canCreate())->toBeFalse();
})->with([
    [ActivityResource::class, Activity::class],
    [BlockTemplateResource::class, BlockTemplate::class],
]);

it('grants Role access through the default RolePolicy only with the generated permission', function (): void {
    $actor = User::factory()->createOne();
    $policy = new RolePolicy;
    expect($policy->viewAny($actor))->toBeFalse();

    $actor->givePermissionTo(Permission::findOrCreate('ViewAny:Role', 'web'));
    $actor->unsetRelation('permissions');

    expect($policy->viewAny($actor))->toBeTrue();
});

it('throws for a future resource with no policy', function (): void {
    test()->actingAs(User::factory()->createOne());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(fn (): Response => get_authorization_response('viewAny', Model::class))->toThrow(LogicException::class);
});

it('has an enforcing policy for every registered admin resource', function (): void {
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    expect($panel->isAuthorizationStrict())->toBeTrue();
    foreach ($panel->getResources() as $resource) {
        expect(Gate::getPolicyFor($resource::getModel()))->not->toBeNull();
    }
});

it('denies existing bulk restore and force-delete operations without permissions', function (string $resource): void {
    test()->actingAs(User::factory()->createOne());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect($resource::canRestoreAny())->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse();
    $subject = class_basename($resource::getModel());
    $actor = auth()->user();
    assert($actor instanceof User);
    $actor->givePermissionTo([
        Permission::findOrCreate('RestoreAny:' . $subject, 'web'),
        Permission::findOrCreate('ForceDeleteAny:' . $subject, 'web'),
    ]);
    expect($resource::canRestoreAny())->toBeTrue()
        ->and($resource::canForceDeleteAny())->toBeTrue();
})->with([
    [LayoutResource::class],
    [SiteResource::class],
]);
