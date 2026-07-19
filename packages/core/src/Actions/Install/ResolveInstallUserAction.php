<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\NewUserData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
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
                $existingUser->forceFill([
                    'name' => $newUser->name,
                    'password' => Hash::make($newUser->password),
                ])->save();
                MarkInstallUserEmailVerifiedAction::run($existingUser);

                $reporter->report('✓ Updated existing user: ' . $newUser->email);

                return $existingUser;
            }

            /** @var Model&Authenticatable $user */
            $user = $userModel::query()->create([
                'name' => $newUser->name,
                'email' => $newUser->email,
                'password' => Hash::make($newUser->password),
            ]);
            MarkInstallUserEmailVerifiedAction::run($user);
            $reporter->report('✓ Created new user: ' . $newUser->email);

            return $user;
        }

        throw new RuntimeException('Either userId or newUser must be provided to ResolveInstallUserAction.');
    }
}
