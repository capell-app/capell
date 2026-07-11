<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\CreateAdditionalInstallUsersAction;
use Capell\Core\Actions\Install\ResolveInstallUserAction;
use Capell\Core\Data\NewUserData;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

it('returns existing user by id', function (): void {
    $user = User::factory()->createOne();
    $reporter = new NullProgressReporter;

    $resolved = ResolveInstallUserAction::run(
        userId: $user->id,
        newUser: null,
        reporter: $reporter,
    );

    expect($resolved->getKey())->toBe($user->getKey());
});

it('creates a new user from NewUserData when no userId given', function (): void {
    $reporter = new NullProgressReporter;
    $newUserData = new NewUserData(
        name: 'Test User',
        email: 'installer@test.com',
        password: 'password',
    );

    $resolved = ResolveInstallUserAction::run(
        userId: null,
        newUser: $newUserData,
        reporter: $reporter,
    );

    expect($resolved->email)->toBe('installer@test.com');

    /** @var class-string<Model> $userModel */
    $userModel = config('auth.providers.users.model');
    expect($userModel::query()->where('email', 'installer@test.com')->exists())->toBeTrue();
});

it('creates additional install users with their configured roles', function (): void {
    $reporter = new NullProgressReporter;

    CreateAdditionalInstallUsersAction::run([
        new NewUserData(
            name: 'Example Super Admin',
            email: 'super-admin@example.test',
            password: 'password123',
            roleName: 'super_admin',
        ),
        new NewUserData(
            name: 'Example Editor',
            email: 'editor@example.test',
            password: 'password123',
            roleName: 'editor',
        ),
    ], $reporter);

    $superAdmin = User::query()->where('email', 'super-admin@example.test')->firstOrFail();
    $editor = User::query()->where('email', 'editor@example.test')->firstOrFail();

    expect($superAdmin->hasRole('super_admin'))->toBeTrue()
        ->and($editor->hasRole('editor'))->toBeTrue()
        ->and(Hash::check('password123', $superAdmin->password))->toBeTrue()
        ->and(Hash::check('password123', $editor->password))->toBeTrue();
});

it('throws when userId given but user does not exist', function (): void {
    $reporter = new NullProgressReporter;

    expect(fn (): mixed => ResolveInstallUserAction::run(
        userId: 99999,
        newUser: null,
        reporter: $reporter,
    ))->toThrow(RuntimeException::class);
});
