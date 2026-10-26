<?php

declare(strict_types=1);

namespace Capell\Installer\Actions\InstallGuide;

use Capell\Core\Data\Patching\PatchPreviewData;
use Capell\Core\Support\Patching\FilePatchReview;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Installer\Data\InstallGuide\ApplyPatchesInputData;
use Capell\Installer\Data\InstallGuide\ApplyPatchesResultData;
use Capell\Installer\Data\InstallGuide\ReviewedPatchData;
use Capell\Installer\Support\InstallGuide\PatchResult;
use Lorisleiva\Actions\Action;
use RuntimeException;

class ApplyReviewedInstallGuidePatchesAction extends Action
{
    /** @param list<string> $patchIds */
    public function handle(array $patchIds, string $token): ApplyPatchesResultData
    {
        abort_unless(auth()->user() !== null && SiteAccess::forActor(auth()->user())->isGlobal(), 403);
        $review = session()->pull('capell.install-guide.review');
        $patchIds = array_values(array_unique($patchIds));
        throw_unless(
            is_array($review)
                && ($review['token'] ?? null) === $token
                && ($review['actor'] ?? null) === auth()->id()
                && ($review['basePath'] ?? null) === base_path()
                && ($review['patchIds'] ?? null) === $patchIds
                && ($review['expiresAt'] ?? 0) > now()->getTimestamp(),
            RuntimeException::class,
            __('capell-installer::install-guide.review_required'),
        );
        $current = BuildInstallGuideReviewAction::run($patchIds);
        throw_unless(
            hash_equals($review['fingerprint'], hash('sha256', serialize($current->toArray()))),
            RuntimeException::class,
            __('capell-installer::install-guide.review_required'),
        );
        $applicableIds = $current->filter(static fn (ReviewedPatchData $patch): bool => $patch->preview instanceof PatchPreviewData)
            ->map(static fn (ReviewedPatchData $patch): string => $patch->patchId)->all();
        $files = [];
        foreach ($current as $patch) {
            array_push($files, ...($patch->preview->files ?? []));
        }

        $result = ApplyInstallGuidePatchesAction::run(new ApplyPatchesInputData($applicableIds, new FilePatchReview($files)));
        foreach ($result->failed() as $failed) {
            $failed->errorMessage = __('capell-installer::install-guide.reviewed_patch_failed');
        }

        foreach ($current as $patch) {
            if ($patch->preview === null) {
                $result->results->push(new PatchResult($patch->patchId, $patch->label, $patch->status, $patch->status, manualRequired: true));
            }
        }

        return $result;
    }
}
