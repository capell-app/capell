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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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

/**
 * @return array{url: string, query: array<array-key, mixed>}
 */
function pendingInvitationQuery(): array
{
    test()->actingAsAdmin();

    Livewire::test(ListUsers::class)->callAction('inviteUser', [
        'name' => 'Client Person',
        'email' => 'client@example.test',
        'preset' => RolePreset::ClientEditor->value,
    ]);

    $invitee = User::query()->where('email', 'client@example.test')->firstOrFail();
    $url = '';

    Notification::assertSentTo($invitee, UserInvitationNotification::class, function (UserInvitationNotification $notification) use ($invitee, &$url): bool {
        $url = (string) $notification->toMail($invitee)->actionUrl;

        return true;
    });

    auth()->logout();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return ['url' => $url, 'query' => $query];
}

it('sends no referrer from the invitation page', function (): void {
    $invitation = pendingInvitationQuery();

    $this->get($invitation['url'])->assertSuccessful()->assertHeader('Referrer-Policy', 'no-referrer');
});

it('shows the invalid-link state when the page opens with a dead token', function (): void {
    $this->get(AcceptInvitation::acceptUrl('not-a-real-token', 'client@example.test'))
        ->assertSuccessful()
        ->assertSee(__('capell-admin::invitations.accept.invalid_heading'))
        ->assertDontSee(__('capell-admin::invitations.accept.subheading', ['email' => 'client@example.test']));
});

it('refuses a tampered email or token', function (string $property): void {
    $invitation = pendingInvitationQuery();

    Livewire::withQueryParams($invitation['query'])
        ->test(AcceptInvitation::class)
        ->set($property, 'someone-else');
})->with(['email', 'token'])->throws(CannotUpdateLockedPropertyException::class);

it('rate limits attempts to accept', function (): void {
    $this->freezeTime();
    $invitation = pendingInvitationQuery();
    $page = Livewire::withQueryParams([...$invitation['query'], 'token' => 'wrong-token'])->test(AcceptInvitation::class);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $page->call('accept');
    }

    $page->call('accept')->assertNotified(__('capell-admin::invitations.accept.throttled', ['seconds' => 60]));
});

it('saves the password but does not sign in someone the panel does not admit', function (): void {
    $invitation = pendingInvitationQuery();
    // Without the panel user role Shield no longer admits the invitee.
    Role::findByName('panel_user')->delete();
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    Livewire::withQueryParams($invitation['query'])
        ->test(AcceptInvitation::class)
        ->fillForm([
            'password' => 'a-long-new-password',
            'passwordConfirmation' => 'a-long-new-password',
        ])
        ->call('accept')
        ->assertRedirect(Filament::getDefaultPanel()->getLoginUrl());

    expect(Filament::auth()->guest())->toBeTrue()
        ->and(Hash::check('a-long-new-password', (string) User::query()->where('email', 'client@example.test')->value('password')))->toBeTrue();
});
