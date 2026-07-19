<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class MarkInstallUserEmailVerifiedAction
{
    use AsFake;
    use AsObject;

    public function handle(Authenticatable $user): void
    {
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }
}
