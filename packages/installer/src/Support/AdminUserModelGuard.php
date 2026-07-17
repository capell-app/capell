<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\UserModelPatch;
use RuntimeException;

final class AdminUserModelGuard
{
    public function ensureUserModelSupportsAdminPackage(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        if (! in_array('capell-app/admin', [
            ...$inputData->packages,
            ...$inputData->extraPackages,
        ], true)) {
            return;
        }

        $patch = new UserModelPatch;
        $status = $patch->probe();

        if ($status === PatchStatus::AlreadyApplied) {
            $reporter->report('✓ User model supports Capell admin roles.');

            return;
        }

        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(sprintf(
                'The installer could not automatically update app/Models/User.php for Capell admin roles because the user model patch status is "%s". Apply the user model install guide patch, then rerun the installer.',
                $status->value,
            ));
        }

        $reporter->step('Patching user model for Capell admin roles…');
        $patch->apply();
        $reporter->report('✓ User model supports Capell admin roles.');
    }

    public function hasInstalledAdminPackageSelection(InstallInputData $inputData): bool
    {
        return in_array('capell-app/admin', $inputData->packages, true);
    }
}
