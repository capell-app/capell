<?php

declare(strict_types=1);

namespace Capell\Installer\Actions\InstallGuide;

use Capell\Core\Data\Patching\PatchPreviewData;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewablePatch;
use Capell\Installer\Data\InstallGuide\ReviewedPatchData;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Action;
use Throwable;

class BuildInstallGuideReviewAction extends Action
{
    /**
     * @param  list<string>  $patchIds
     * @return Collection<int, ReviewedPatchData>
     */
    public function handle(array $patchIds): Collection
    {
        $patches = collect();
        foreach ($patchIds as $id) {
            $patch = resolve(PatchRegistry::class)->get($id);
            $status = $patch?->probe() ?? PatchStatus::Unsupported;
            $preview = null;
            if ($patch instanceof ReviewablePatch && $status === PatchStatus::Applicable) {
                try {
                    $preview = $patch->preview();
                } catch (Throwable) {
                    // Parser and extension exceptions may contain private file contents.
                }
            }

            $patches->push(new ReviewedPatchData(
                patchId: $id,
                label: $patch?->label() ?? $id,
                status: $status,
                preview: $preview,
                guidance: $preview instanceof PatchPreviewData
                    ? ($preview->backsUp
                        ? __('capell-installer::install-guide.preview_backup')
                        : __('capell-installer::install-guide.preview_creation'))
                    : __('capell-installer::install-guide.preview_manual'),
            ));
        }

        return $patches;
    }
}
