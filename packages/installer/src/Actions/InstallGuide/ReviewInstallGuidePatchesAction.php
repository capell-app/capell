<?php

declare(strict_types=1);

namespace Capell\Installer\Actions\InstallGuide;

use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Installer\Data\InstallGuide\ReviewPatchesResultData;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Action;

class ReviewInstallGuidePatchesAction extends Action
{
    /** @param list<string> $patchIds */
    public function handle(array $patchIds): ReviewPatchesResultData
    {
        abort_unless(auth()->user() !== null && SiteAccess::forActor(auth()->user())->isGlobal(), 403);
        $patchIds = array_values(array_unique($patchIds));
        $patches = BuildInstallGuideReviewAction::run($patchIds);
        $token = (string) Str::uuid();
        session()->put('capell.install-guide.review', [
            'token' => $token,
            'actor' => auth()->id(),
            'basePath' => base_path(),
            'patchIds' => $patchIds,
            'fingerprint' => hash('sha256', serialize($patches->toArray())),
            'expiresAt' => now()->addMinutes(15)->getTimestamp(),
        ]);

        return new ReviewPatchesResultData($token, $patches);
    }
}
