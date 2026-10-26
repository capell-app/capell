<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide;

use Capell\Core\Actions\Install\ApplyFilePatchPlanAction;
use Capell\Core\Data\Patching\PatchPreviewData;
use Capell\Core\Data\Patching\PatchRecoveryData;
use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\FilePatchReview;
use Capell\Core\Support\Patching\PatchStatus;
use Override;
use RuntimeException;

trait PlansFilePatch
{
    /** @var list<string> */
    private array $recovery = [];

    abstract protected function plan(): FilePatchPlan;

    #[Override]
    public function preview(): PatchPreviewData
    {
        return $this->plan()->preview();
    }

    #[Override]
    public function recoveryResult(): PatchRecoveryData
    {
        return new PatchRecoveryData($this->recovery);
    }

    #[Override]
    public function apply(): void
    {
        $this->applyPlan();
    }

    #[Override]
    public function applyReviewed(FilePatchReview $review): void
    {
        $this->applyPlan($review);
    }

    private function applyPlan(?FilePatchReview $review = null): void
    {
        $this->recovery = [];
        $status = $this->probe();
        throw_unless($status === PatchStatus::Applicable, RuntimeException::class, 'Cannot apply patch when status is: ' . $status->value);
        ApplyFilePatchPlanAction::run($this->plan(), function (string $path, bool $created): void {
            $this->recovery[] = $path;
        }, $review);
    }
}
