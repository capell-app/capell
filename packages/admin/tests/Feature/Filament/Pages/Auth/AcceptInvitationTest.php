<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Enums\RolePreset;
use Capell\Admin\Filament\Pages\Auth\AcceptInvitation;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Notifications\UserInvitationNotification;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    // Real installs admit people to the panel through Shield's panel user role.
    config()->set('filament-shield.panel_user.enabled', true);
    AssignPermissionsToRole::run(resources: ResourceEnum::cases());
    EnsureCapellPermissionsAction::run();
    SeedDefaultRolesAction::run();
    Notification::fake();
});

it('invites a client editor from the users list and lets them sign in after choosing a password', function (): void {
    test()->actingAsAdmin();

    Livewire::test(ListUsers::class)
        ->callAction('inviteUser', [
            'name' => 'Client Person',
            'email' => 'client@example.test',
            'preset' => RolePreset::ClientEditor->value,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $invitee = User::query()->where('email', 'client@example.test')->firstOrFail();
    $url = null;

    Notification::assertSentTo($invitee, UserInvitationNotification::class, function (UserInvitationNotification $notification) use ($invitee, &$url): bool {
        $url = $notification->toMail($invitee)->actionUrl;

        return true;
    });

    auth()->logout();

    $this->get((string) $url)->assertSuccessful()->assertSee('client@example.test');

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    Livewire::withQueryParams($query)->test(AcceptInvitation::class)
        ->fillForm([
            'password' => 'a-long-new-password',
            'passwordConfirmation' => 'a-long-new-password',
        ])
        ->call('accept')
        ->assertHasNoFormErrors()
        ->assertNotNotified()
        ->assertRedirect();

    expect(Filament::auth()->id())->toBe($invitee->getKey());
});

it('rejects an invitation page without a valid signature', function (): void {
    $this->get(AcceptInvitation::acceptUrl('token', 'client@example.test') . 'tampered')->assertForbidden();
});
