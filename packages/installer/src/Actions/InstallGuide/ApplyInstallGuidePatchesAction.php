<?php

declare(strict_types=1);

namespace Capell\Installer\Actions\InstallGuide;

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

            $statusBefore = $patch->probe();

            try {
                $patch->apply();
                $statusAfter = $patch->probe();

                $patchResult = new PatchResult(
                    patchId: $patchId,
                    label: $patch->label(),
                    statusBefore: $statusBefore,
                    statusAfter: $statusAfter,
                );
            } catch (Throwable $exception) {
                $patchResult = new PatchResult(
                    patchId: $patchId,
                    label: $patch->label(),
                    statusBefore: $statusBefore,
                    statusAfter: $statusBefore,
                    errorMessage: $this->manualChangeMessage($exception->getMessage()),
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
