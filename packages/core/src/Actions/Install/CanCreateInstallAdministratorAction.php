<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\NewUserData;
use Capell\Core\Support\Install\Cli\FreshInstallDefaults;
use Lorisleiva\Actions\Concerns\AsObject;

final class CanCreateInstallAdministratorAction
{
    use AsObject;

    public function handle(?NewUserData $user, bool $allowDemoCredentials = false): bool
    {
        $demoUser = FreshInstallDefaults::adminUser();
        if (! app()->environment('production')) {
            return true;
        }

        if ($allowDemoCredentials) {
            return true;
        }

        if (! $user instanceof NewUserData) {
            return true;
        }

        if (strtolower($user->email) !== $demoUser->email) {
            return true;
        }

        return $user->password !== $demoUser->password;
    }
}
