<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Actions\Install\ApplyFilePatchPlanAction;
use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Data\Patching\PatchPreviewData;
use Capell\Core\Data\Patching\PatchRecoveryData;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\PanelProviderTargetResolver;
use Capell\Core\Support\Install\RemembersInstallPatchDecision;
use Capell\Core\Support\Patching\FilePatchReview;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Override;
use RuntimeException;

class AdminPanelThemePatch implements RemembersInstallPatchDecision, ReviewableFilePatch
{
    private ?PatchRecoveryData $recovery = null;

    public function __construct(
        private readonly ?string $providerPath = null,
        private readonly bool $adoptDefault = false,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'admin-panel-theme-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'providers';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_theme_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_theme_patch_description');
    }

    #[Override]
    public function docUrl(): ?string
    {
        return null;
    }

    #[Override]
    public function defaultEnabled(): bool
    {
        return true;
    }

    #[Override]
    public function probe(): PatchStatus
    {
        $path = $this->target();

        return $path !== null ? resolve(InstallFilamentPanelAction::class)->themePatchStatus($path) : PatchStatus::Unsupported;
    }

    #[Override]
    public function reason(): ?string
    {
        return null;
    }

    #[Override]
    public function preview(): PatchPreviewData
    {
        $path = $this->target();
        throw_if($path === null, RuntimeException::class, 'No panel target is available.');
        $plans = resolve(InstallFilamentPanelAction::class)->themeFilePlans($path);
        $files = [];
        $fingerprints = [];
        $backsUp = true;
        foreach ($plans as $plan) {
            $preview = $plan->preview();
            array_push($files, ...$preview->files);
            $fingerprints[] = $preview->fingerprint;
            $backsUp = $backsUp && $preview->backsUp;
        }

        return new PatchPreviewData($files, hash('sha256', serialize($fingerprints)), $backsUp);
    }

    #[Override]
    public function recoveryResult(): PatchRecoveryData
    {
        return $this->recovery ?? new PatchRecoveryData;
    }

    #[Override]
    public function apply(): void
    {
        $this->applyPlans();
    }

    #[Override]
    public function applyReviewed(FilePatchReview $review): void
    {
        $this->applyPlans($review);
    }

    #[Override]
    public function rememberDecision(bool $accepted): void
    {
        InstallRunState::rememberPanelThemeDecision($this->target(), $accepted);
    }

    #[Override]
    public function declined(): bool
    {
        return InstallRunState::panelThemeDeclined($this->target());
    }

    private function applyPlans(?FilePatchReview $review = null): void
    {
        $this->recovery = new PatchRecoveryData;
        $path = $this->target();
        throw_unless($path !== null && $this->probe() === PatchStatus::Applicable, RuntimeException::class, 'Cannot apply patch when status is: ' . $this->probe()->value);
        $plans = resolve(InstallFilamentPanelAction::class)->themeFilePlans($path);
        // Applying this patch is an explicit opt-in, even after an earlier decline.
        InstallRunState::rememberPanelThemeDecision($path, accepted: true);
        foreach ($plans as $plan) {
            ApplyFilePatchPlanAction::run($plan, function (string $file, bool $created): void {
                if ($created) {
                    $this->recoveryResult()->createdPaths[] = $file;
                } else {
                    $this->recoveryResult()->backupPaths[] = $file;
                }
            }, $review);
        }
    }

    private function target(): ?string
    {
        return resolve(PanelProviderTargetResolver::class)->resolve($this->providerPath, $this->adoptDefault)[0] ?? null;
    }
}
