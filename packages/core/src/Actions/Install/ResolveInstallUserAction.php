<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\NewUserData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class ResolveInstallUserAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $userId, ?NewUserData $newUser, ProgressReporter $reporter): Authenticatable
    {
        /** @var class-string<Model&Authenticatable> $userModel */
        $userModel = config('auth.providers.users.model');

        if ($userId !== null) {
            $user = $userModel::query()->find($userId);
            if (! ($user instanceof Authenticatable)) {
                throw new RuntimeException(sprintf('User with ID %d not found.', $userId));
            }

            $reporter->report('✓ Using existing user: ' . $user->getAuthIdentifier());

            return $user;
        }

        if ($newUser instanceof NewUserData) {
            $existingUser = $userModel::query()
                ->where('email', $newUser->email)
                ->first();

            if ($existingUser instanceof Authenticatable) {
                throw ValidationException::withMessages([
                    'new_user_email' => __('validation.unique', ['attribute' => 'email']),
                ]);
            }

            $user = new $userModel()->getConnection()->transaction(function () use ($userModel, $newUser): Authenticatable {
                /** @var Model&Authenticatable $user */
                $user = $userModel::query()->create([
                    'name' => $newUser->name,
                    'email' => $newUser->email,
                    'password' => Hash::make($newUser->password),
                ]);
                MarkInstallUserEmailVerifiedAction::run($user);

                return $user;
            });
            $reporter->report('✓ Created new user: ' . $newUser->email);

            return $user;
        }

        throw new RuntimeException('Either userId or newUser must be provided to ResolveInstallUserAction.');
    }
}
