<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Data\Users\UserInvitationData;
use Capell\Admin\Enums\RolePreset;
use Capell\Admin\Filament\Pages\Auth\AcceptInvitation;
use Capell\Admin\Notifications\UserInvitationNotification;
use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Capell\Admin\Support\Users\RoleGrantGuard;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;

/**
 * Creates an account for someone, gives them a role preset and emails them a
 * link to choose their own password. The owner never sets or sees a password.
 */
final class InviteUserAction
{
    use AsFake;
    use AsObject;

    /**
     * Inviting hands out a role, so it needs the same reach as assigning roles
     * on the user form: permission to create users and global (all-site) access.
     *
     * @param  class-string<Authenticatable>  $userModel
     */
    public static function canInvite(Authenticatable $actor, ?string $userModel = null): bool
    {
        $userModel ??= config('auth.providers.users.model');

        if (! is_string($userModel) || ! Gate::forUser($actor)->allows('create', $userModel)) {
            return false;
        }

        if (method_exists($actor, 'isGlobalAdmin') && $actor->isGlobalAdmin()) {
            return true;
        }

        return SiteAccess::forActor($actor)->isGlobal();
    }

    /**
     * The actor may only hand out a preset whose every current permission they
     * hold themselves; see RoleGrantGuard.
     */
    public static function canGrantPreset(Authenticatable $actor, RolePreset $preset): bool
    {
        return self::canInvite($actor) && resolve(RoleGrantGuard::class)->canGrant($actor, self::roleFor($preset));
    }

    /**
     * @return list<RolePreset>
     */
    public static function grantablePresets(Authenticatable $actor): array
    {
        if (! self::canInvite($actor)) {
            return [];
        }

        return array_values(array_filter(
            RolePreset::cases(),
            static fn (RolePreset $preset): bool => resolve(RoleGrantGuard::class)->canGrant($actor, self::roleFor($preset)),
        ));
    }

    /**
     * Matches sign-in, which treats addresses that differ only in case as the
     * same person.
     *
     * @param  class-string<Authenticatable>  $userModel
     */
    public static function emailIsTaken(string $userModel, string $email): bool
    {
        return $userModel::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->exists();
    }

    public static function roleFor(RolePreset $preset): Role
    {
        $guardName = (string) config('auth.defaults.guard', 'web');
        $role = Role::query()
            ->where('name', $preset->roleName())
            ->where('guard_name', $guardName)
            ->first();

        if ($role instanceof Role) {
            return $role;
        }

        SeedRolePresetRolesAction::run(syncExisting: false);

        return Role::query()
            ->where('name', $preset->roleName())
            ->where('guard_name', $guardName)
            ->firstOrFail();
    }

    public function handle(UserInvitationData $invitation, Authenticatable $actor): Authenticatable
    {
        /** @var class-string<Authenticatable> $userModel */
        $userModel = config('auth.providers.users.model');

        throw_unless(self::canInvite($actor, $userModel), AuthorizationException::class);

        $role = self::roleFor($invitation->preset);

        throw_unless(resolve(RoleGrantGuard::class)->canGrant($actor, $role), AuthorizationException::class);

        if (self::emailIsTaken($userModel, $invitation->email)) {
            throw ValidationException::withMessages([
                'email' => __('capell-admin::invitations.validation.email_taken'),
            ]);
        }

        /** @var Authenticatable&CanResetPassword $user */
        $user = DB::transaction(function () use ($userModel, $invitation, $role): Model {
            $user = new $userModel;
            $user->forceFill([
                'name' => $invitation->name,
                'email' => $invitation->email,
                // Unusable until the invitee chooses their own password.
                'password' => Hash::make(Str::random(64)),
            ])->save();

            $user->assignRole($role);

            // Shield admits people to the panel through this role. Its own
            // assign-on-create hook skips console runs, so assign it here too.
            if (Utils::isPanelUserRoleEnabled() && method_exists($user, 'hasRole') && ! $user->hasRole(Utils::getPanelUserRoleName())) {
                $user->assignRole(Role::findOrCreate(Utils::getPanelUserRoleName(), $role->guard_name));
            }

            return $user;
        });

        $token = InvitationPasswordBroker::broker()->createToken($user);

        $user->notify(new UserInvitationNotification(
            acceptUrl: AcceptInvitation::acceptUrl($token, $invitation->email),
            inviterName: (string) $actor->getAttribute('name'),
            siteName: (string) config('app.name'),
            preset: $invitation->preset,
        ));

        return $user;
    }
}
