<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Actions\Users\AcceptUserInvitationAction;
use Capell\Admin\Actions\Users\InviteUserAction;
use Capell\Admin\Actions\Users\ResendUserInvitationAction;
use Capell\Admin\Data\Users\UserInvitationData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Enums\RolePreset;
use Capell\Admin\Notifications\UserInvitationNotification;
use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Capell\Admin\Support\Users\RoleGrantGuard;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    AssignPermissionsToRole::run(resources: ResourceEnum::cases());
    EnsureCapellPermissionsAction::run();
    SeedDefaultRolesAction::run();
    Notification::fake();
});

function inviteClient(?User $actor = null, string $email = 'client@example.test'): User
{
    $actor ??= test()->createUserWithRole('super_admin');

    /** @var User $user */
    $user = InviteUserAction::run(new UserInvitationData('Client Person', $email, RolePreset::ClientEditor), $actor);

    return $user;
}

function invitationUrlFor(User $user): string
{
    $url = null;

    Notification::assertSentTo($user, UserInvitationNotification::class, function (UserInvitationNotification $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return is_string($url);
    });

    return (string) $url;
}

/**
 * @return array{token: string, email: string}
 */
function invitationQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? null;
    $email = $query['email'] ?? null;

    throw_unless(is_string($token) && is_string($email), RuntimeException::class, 'The invitation link is missing its token or email.');

    return ['token' => $token, 'email' => $email];
}

it('creates the account with the chosen preset and emails a set-password link instead of asking the owner for a password', function (): void {
    $user = inviteClient();

    expect($user->email)->toBe('client@example.test')
        ->and($user->hasRole(RolePreset::ClientEditor->roleName()))->toBeTrue()
        ->and(invitationUrlFor($user))->toContain('signature=')->toContain('expires=');
});

it('lets the invitee choose a password once with the emailed link', function (): void {
    $user = inviteClient();
    $query = invitationQuery(invitationUrlFor($user));

    $accepted = AcceptUserInvitationAction::run($query['email'], $query['token'], 'a-long-new-password');

    expect($accepted?->is($user))->toBeTrue()
        ->and(Hash::check('a-long-new-password', (string) $user->fresh()?->password))->toBeTrue()
        ->and(AcceptUserInvitationAction::run($query['email'], $query['token'], 'another-password-1'))->toBeNull();
});

it('refuses an invitation link older than seven days', function (): void {
    $user = inviteClient();
    $query = invitationQuery(invitationUrlFor($user));

    $this->travel(7)->days();
    $this->travel(1)->minutes();

    expect(AcceptUserInvitationAction::run($query['email'], $query['token'], 'a-long-new-password'))->toBeNull();
});

it('refuses to invite an email address that already has an account', function (): void {
    test()->createUser(['email' => 'client@example.test']);

    inviteClient();
})->throws(ValidationException::class);

it('only lets people who can assign roles across every site send invitations', function (): void {
    $editor = test()->createUserWithRole('editor');

    inviteClient($editor);
})->throws(AuthorizationException::class);

it('sends a fresh link that replaces the earlier one', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $user = inviteClient($actor);
    $first = invitationQuery(invitationUrlFor($user));

    Notification::fake();
    ResendUserInvitationAction::run($user, $actor);
    $second = invitationQuery(invitationUrlFor($user));

    expect(AcceptUserInvitationAction::run($first['email'], $first['token'], 'a-long-new-password'))->toBeNull()
        ->and(AcceptUserInvitationAction::run($second['email'], $second['token'], 'a-long-new-password'))->not->toBeNull();
});

it('treats an address that differs only in case as already taken', function (): void {
    test()->createUser(['email' => 'Client@Example.TEST']);

    inviteClient(email: 'client@example.test');
})->throws(ValidationException::class);

it('never lets a normal password reset token redeem an invitation', function (): void {
    $user = inviteClient();
    $resetToken = Password::broker()->createToken($user);

    expect(AcceptUserInvitationAction::run('client@example.test', $resetToken, 'a-long-new-password'))->toBeNull()
        ->and(Password::broker()->tokenExists($user, invitationQuery(invitationUrlFor($user))['token']))->toBeFalse();
});

it('takes every invitation lifetime from the broker configuration', function (): void {
    InvitationPasswordBroker::ensureConfigured();
    config(['auth.passwords.' . InvitationPasswordBroker::NAME . '.expire' => 60 * 24 * 2]);

    $this->freezeTime();
    $user = inviteClient();
    $url = invitationUrlFor($user);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $mail = new UserInvitationNotification($url, 'Owner', 'Site', RolePreset::ClientEditor)->toMail($user);
    $lines = implode(' ', array_map(strval(...), [...$mail->introLines, ...$mail->outroLines]));

    expect((int) $query['expires'])->toBe(now()->addDays(2)->getTimestamp())
        ->and($lines)->toContain('2 days')->not->toContain('7 days');

    $this->travel(2)->days();
    $this->travel(1)->minutes();

    expect(AcceptUserInvitationAction::run('client@example.test', invitationQuery($url)['token'], 'a-long-new-password'))->toBeNull();
});

it('refuses to resend to yourself or as someone without rights to change users', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $user = inviteClient($actor);

    expect(ResendUserInvitationAction::canResend($user, $actor))->toBeTrue()
        ->and(ResendUserInvitationAction::canResend($actor, $actor))->toBeFalse()
        ->and(ResendUserInvitationAction::canResend($user, test()->createUserWithRole('editor')))->toBeFalse();
});

it('offers resend only while the invitation is pending', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $user = inviteClient($actor);
    $query = invitationQuery(invitationUrlFor($user));

    AcceptUserInvitationAction::run($query['email'], $query['token'], 'a-long-new-password');

    expect(ResendUserInvitationAction::canResend($user->fresh() ?? $user, $actor))->toBeFalse();
});

it('kills the invitation link once the invitee signs in another way', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $user = inviteClient($actor);
    $token = invitationQuery(invitationUrlFor($user))['token'];

    event(new Login('web', $user, false));

    expect(InvitationPasswordBroker::isValid('client@example.test', $token))->toBeFalse()
        ->and(ResendUserInvitationAction::canResend($user, $actor))->toBeFalse();
});

it('kills the invitation link after a normal password reset', function (): void {
    $user = inviteClient();
    $token = invitationQuery(invitationUrlFor($user))['token'];

    $status = Password::broker()->reset(
        ['email' => 'client@example.test', 'token' => Password::broker()->createToken($user), 'password' => 'reset-password-123'],
        function (User $resetUser, string $password): void {
            $resetUser->forceFill(['password' => Hash::make($password)])->save();
            event(new PasswordReset($resetUser));
        },
    );

    expect($status)->toBe(Password::PASSWORD_RESET)
        ->and(AcceptUserInvitationAction::run('client@example.test', $token, 'taken-over-password'))->toBeNull();
});

it('kills the invitation link when someone saves a password for the invitee', function (): void {
    $user = inviteClient();
    $token = invitationQuery(invitationUrlFor($user))['token'];

    $user->forceFill(['password' => Hash::make('set-by-an-admin-123')])->save();

    expect(AcceptUserInvitationAction::run('client@example.test', $token, 'taken-over-password'))->toBeNull();
});

it('refuses an inviter who may invite but lacks a permission the preset grants', function (): void {
    $actor = test()->createUserWithRole('super_admin');

    // Today only global administrators pass canInvite, and they hold every
    // permission; stand in a guard that says the inviter lacks one.
    app()->instance(RoleGrantGuard::class, new class extends RoleGrantGuard
    {
        public function canGrant(Authenticatable $actor, Role $role): bool
        {
            return false;
        }
    });

    expect(InviteUserAction::canInvite($actor))->toBeTrue();

    try {
        inviteClient($actor);
    } finally {
        expect(User::query()->where('email', 'client@example.test')->exists())->toBeFalse();
        Notification::assertNothingSent();
    }
})->throws(AuthorizationException::class);

it('keeps Capell defaults for anything a host invitation broker config leaves out', function (): void {
    config(['auth.passwords.' . InvitationPasswordBroker::NAME => ['expire' => 120]]);

    InvitationPasswordBroker::ensureConfigured();

    expect(InvitationPasswordBroker::expiresInMinutes())->toBe(120)
        ->and(InvitationPasswordBroker::table())->toContain('admin_user_invitation_tokens')
        ->and(config('auth.passwords.' . InvitationPasswordBroker::NAME . '.provider'))->toBe('users');
});
