<?php

declare(strict_types=1);

use Capell\Admin\Actions\Diagnostics\CheckAdminPanelAccessAction;
use Capell\Admin\Tests\Fixtures\Models\DiagnosticsDeniedUser;
use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

it('reports critical evidence when no configured users exist', function (): void {
    $result = CheckAdminPanelAccessAction::run();

    expect($result->passed)->toBeFalse()
        ->and($result->id)->toBe('core.admin.access')
        ->and($result->evidence['user_count'])->toBe(0)
        ->and($result->evidence)->not->toHaveKeys(['email', 'name']);
});

it('proves effective access for a correctly assigned admin', function (): void {
    $user = User::factory()->createOne();
    $role = Role::query()->firstOrCreate([
        'name' => config('filament-shield.super_admin.name', 'super_admin'),
        'guard_name' => 'web',
    ]);
    $user->assignRole($role);

    $result = CheckAdminPanelAccessAction::run();

    expect($result->passed)->toBeTrue()
        ->and($result->evidence['accessible_user_count'])->toBe(1)
        ->and($result->evidence['matching_assignment_count'])->toBe(1);
});

it('rejects orphan, wrong-guard, and wrong-morph role assignments', function (string $guard, string $morphType, int $modelId): void {
    User::factory()->createOne();
    $role = Role::query()->firstOrCreate([
        'name' => config('filament-shield.super_admin.name', 'super_admin'),
        'guard_name' => $guard,
    ]);
    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $morphType,
        'model_id' => $modelId,
        'team_id' => null,
    ]);

    $result = CheckAdminPanelAccessAction::run();

    expect($result->passed)->toBeFalse()
        ->and($result->evidence['accessible_user_count'])->toBe(0);
})->with([
    'orphan assignment' => ['web', User::class, 999_999],
    'wrong guard' => ['api', User::class, 1],
    'wrong morph type' => ['web', 'another-user-type', 1],
]);

it('rejects an invalid configured user model', function (): void {
    config()->set('auth.providers.users.model', stdClass::class);

    $result = CheckAdminPanelAccessAction::run();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('user model is invalid');
});

it('rejects users whose real panel access contract denies access', function (): void {
    config()->set('auth.providers.users.model', DiagnosticsDeniedUser::class);
    DiagnosticsDeniedUser::query()->create([
        'name' => 'Denied',
        'email' => 'denied@example.test',
        'password' => bcrypt('password'),
    ]);

    $result = CheckAdminPanelAccessAction::run();

    expect($result->passed)->toBeFalse()
        ->and($result->evidence['accessible_user_count'])->toBe(0);
});

it('reports when the users table is absent', function (): void {
    Schema::shouldReceive('hasTable')
        ->andReturnUsing(static fn (string $table): bool => $table !== 'users')
        ->byDefault();

    $result = CheckAdminPanelAccessAction::run();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toBe('The users table does not exist.');
});

it('reports users without an effective admin role assignment', function (): void {
    User::factory()->createOne();

    $result = CheckAdminPanelAccessAction::run();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toBe('Users exist but no role assignments were found.');
});

it('contributes the Admin-owned access check to the Core doctor report', function (): void {
    $checks = BuildDoctorReportAction::run(includePackageDoctors: false)->checks;

    expect($checks->where('id', 'core.admin.access'))->toHaveCount(1);
});
