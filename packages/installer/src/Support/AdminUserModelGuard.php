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
        if ($patch->isReadyForAdmin()) {
            $reporter->report(__('capell-installer::install-guide.user_model_admin_ready'));

            return;
        }

        $status = $patch->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(trim(
                __('capell-installer::install-guide.user_model_admin_not_ready', ['status' => $status->value])
                . ' ' . ($patch->reason() ?? ''),
            ));
        }

        $reporter->step(__('capell-installer::install-guide.user_model_admin_preparing'));
        $patch->apply();
        $reporter->report(__('capell-installer::install-guide.user_model_admin_ready'));
    }

    public function hasInstalledAdminPackageSelection(InstallInputData $inputData): bool
    {
        return in_array('capell-app/admin', $inputData->packages, true);
    }
}
