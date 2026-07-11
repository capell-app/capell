<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\Pages\CreateUser;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(CreatesAdminUser::class)->group('user');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('creates a user', function (): void {
    $payload = [
        'name' => 'Test User',
        'email' => 'testuser@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'roles' => [],
    ];

    Livewire::test(CreateUser::class)
        ->assertSee(__('capell-admin::form.user_identity'))
        ->assertSee(__('capell-admin::generic.user_identity_description'))
        ->assertSee(__('capell-admin::form.user_credentials'))
        ->assertSee(__('capell-admin::generic.user_credentials_description'))
        ->assertSee(__('capell-admin::generic.user_password_confirmation_info'))
        ->assertSee(__('capell-admin::generic.user_profile_description'))
        ->fillForm($payload)
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('users', [
        'name' => 'Test User',
        'email' => 'testuser@example.com',
    ]);
});

it('shows validation errors for missing required fields', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => '',
            'email' => '',
            'password' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['name', 'email', 'password']);
});

it('shows validation error for invalid email', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Invalid Email',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);
});
