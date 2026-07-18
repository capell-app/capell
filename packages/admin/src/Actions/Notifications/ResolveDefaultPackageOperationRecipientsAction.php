<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Notifications;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static Collection<int, Model> run() */
final class ResolveDefaultPackageOperationRecipientsAction
{
    use AsFake;
    use AsObject;

    /** @return Collection<int, Model> */
    public function handle(): Collection
    {
        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! is_a($userModel, Model::class, true)) {
            return new Collection;
        }

        $role = config('capell.roles.super_admin', 'super_admin');
        $model = new $userModel;
        $query = $model->newQuery()->orderBy($model->qualifyColumn($model->getKeyName()));

        $globalAdminsScope = [$model, 'scopeGlobalAdmins'];

        if (
            is_string($role)
            && method_exists($model, 'roles')
            && method_exists($model, 'hasRole')
            && method_exists($model, 'scopeGlobalAdmins')
            && method_exists($model, 'isGlobalAdmin')
        ) {
            $applyGlobalAdminsScope = Closure::fromCallable($globalAdminsScope);

            return $query
                ->where(static function (Builder $query) use ($applyGlobalAdminsScope, $role): void {
                    $applyGlobalAdminsScope($query);
                    $query->orWhereHas('roles', static fn (Builder $roleQuery): Builder => $roleQuery->where('name', $role));
                })
                ->get();
        }

        return $query->get()
            ->filter(fn (Model $user): bool => $this->isAdminRecipient($user, $role))
            ->values();
    }

    private function isAdminRecipient(Model $user, mixed $role): bool
    {
        if (method_exists($user, 'isGlobalAdmin') && $user->isGlobalAdmin()) {
            return true;
        }

        return method_exists($user, 'hasRole') && $user->hasRole($role);
    }
}
