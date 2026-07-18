<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Notifications;

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

        return $userModel::query()
            ->get()
            ->filter(function (Model $user): bool {
                if (method_exists($user, 'isGlobalAdmin') && $user->isGlobalAdmin()) {
                    return true;
                }

                return method_exists($user, 'hasRole')
                    && $user->hasRole(config('capell.roles.super_admin', 'super_admin'));
            })
            ->values();
    }
}
