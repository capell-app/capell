<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveMarketplaceInstallAttemptUserAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt): Model|Authenticatable|null
    {
        if ($attempt->user_id === null || $attempt->user_id === '') {
            return null;
        }

        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! class_exists($userModel)) {
            return null;
        }

        $user = new $userModel;

        if (! $user instanceof Model) {
            return null;
        }

        $foundUser = $user->newQuery()->whereKey($attempt->user_id)->first();

        return $foundUser instanceof Model || $foundUser instanceof Authenticatable
            ? $foundUser
            : null;
    }
}
