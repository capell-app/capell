<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Patching\PatchStatus;
use Closure;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class PrepareInstallApplicationAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Closure(InstallPatchConfirmation): bool  $confirmPatch
     * @param  Closure(string): void  $recordManualInstallChange
     */
    public function handle(
        InstallInputData $inputData,
        bool $hasFilamentAdminPanelProvider,
        bool $interactive,
        bool $useFreshDemoDefaults,
        ProgressReporter $reporter,
        Closure $confirmPatch,
        Closure $recordManualInstallChange,
    ): void {
        $selectedPackageNames = array_values(array_unique([
            ...$inputData->packages,
            ...$inputData->extraPackages,
        ]));

        $patchContext = new InstallPatchContext(
            packageNames: $selectedPackageNames,
            hasFilamentAdminPanelProvider: $hasFilamentAdminPanelProvider,
        );

        foreach (resolve(InstallPatchRegistry::class)->patchesFor($patchContext) as $registeredPatch) {
            $patch = $registeredPatch->patch;
            $status = $patch->probe();

            if ($status === PatchStatus::AlreadyApplied) {
                continue;
            }

            if ($status !== PatchStatus::Applicable) {
                $recordManualInstallChange(sprintf(
                    '%s: patch status is "%s".',
                    $patch->label(),
                    $status->value,
                ));

                $reporter->error(sprintf(
                    '⚠ %s was not applied automatically. Manual changes may be required.',
                    $patch->label(),
                ));

                continue;
            }

            $confirmation = $registeredPatch->confirmation;

            if ($confirmation instanceof InstallPatchConfirmation
                && $interactive
                && ! $useFreshDemoDefaults
                && ! $confirmPatch($confirmation)
            ) {
                if ($confirmation->skippedMessage !== null) {
                    $reporter->report($confirmation->skippedMessage);
                }

                continue;
            }

            $reporter->step('Applying install guide patch: ' . $patch->label());

            try {
                $patch->apply();
            } catch (Throwable $throwable) {
                $recordManualInstallChange(sprintf(
                    '%s: %s',
                    $patch->label(),
                    $throwable->getMessage(),
                ));

                $reporter->error(sprintf(
                    '⚠ %s was not applied automatically. Manual changes may be required.',
                    $patch->label(),
                ));
                $reporter->error($throwable->getMessage());
            }
        }
    }
}
