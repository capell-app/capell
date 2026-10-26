<?php

declare(strict_types=1);

namespace Capell\Installer\Actions\InstallGuide;

use Capell\Core\Support\Patching\FilePatchReview;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Core\Support\Patching\ReviewablePatch;
use Capell\Installer\Data\InstallGuide\ApplyPatchesInputData;
use Capell\Installer\Data\InstallGuide\ApplyPatchesResultData;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Installer\Support\InstallGuide\PatchResult;
use Lorisleiva\Actions\Action;
use Throwable;

class ApplyInstallGuidePatchesAction extends Action
{
    private const string INSTALL_PERMISSIONS_DOC_URL = 'https://docs.capell.app/getting-started/install/#install-time-write-permissions';

    public function handle(ApplyPatchesInputData $input): ApplyPatchesResultData
    {
        $patchRegistry = resolve(PatchRegistry::class);
        $results = collect();

        foreach ($input->patchIds as $patchId) {
            $patch = $patchRegistry->get($patchId);

            // Skip if patch not found in registry
            if ($patch === null) {
                continue;
            }

            $statusBefore = PatchStatus::Unsupported;

            try {
                $statusBefore = $patch->probe();
                if ($input->fileReview instanceof FilePatchReview && $patch instanceof ReviewableFilePatch) {
                    $patch->applyReviewed($input->fileReview);
                } else {
                    $patch->apply();
                }

                $statusAfter = $patch->probe();

                $patchResult = new PatchResult(
                    patchId: $patchId,
                    label: $patch->label(),
                    statusBefore: $statusBefore,
                    statusAfter: $statusAfter,
                    recoveryPaths: $patch instanceof ReviewablePatch ? $patch->recoveryResult()->backupPaths : [],
                    createdPaths: $patch instanceof ReviewablePatch ? $patch->recoveryResult()->createdPaths : [],
                );
            } catch (Throwable $exception) {
                $patchResult = new PatchResult(
                    patchId: $patchId,
                    label: $patch->label(),
                    statusBefore: $statusBefore,
                    statusAfter: $statusBefore,
                    errorMessage: $this->manualChangeMessage($exception->getMessage()),
                    recoveryPaths: $patch instanceof ReviewablePatch ? $patch->recoveryResult()->backupPaths : [],
                    createdPaths: $patch instanceof ReviewablePatch ? $patch->recoveryResult()->createdPaths : [],
                );
            }

            $results->push($patchResult);
        }

        return new ApplyPatchesResultData(
            results: $results,
        );
    }

    private function manualChangeMessage(string $message): string
    {
        return sprintf(
            '%s Manual changes may be required. Review the install-time write permissions and manual patch list: %s',
            $message,
            self::INSTALL_PERMISSIONS_DOC_URL,
        );
    }
}
