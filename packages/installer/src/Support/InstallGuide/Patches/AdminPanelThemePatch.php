<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Install\PanelProviderTargetResolver;
use Capell\Core\Support\Install\RemembersInstallPatchDecision;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use Override;
use RuntimeException;
use Throwable;

class AdminPanelThemePatch implements Patch, RemembersInstallPatchDecision
{
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
    public function apply(): void
    {
        try {
            $path = $this->target();
            $action = resolve(InstallFilamentPanelAction::class);
            $status = $path !== null ? $action->themePatchStatus($path) : PatchStatus::Unsupported;
            throw_unless($path !== null && $status === PatchStatus::Applicable, RuntimeException::class, 'Cannot apply patch when status is: ' . $status->value);

            new PhpFileEditor($path)->backup();
            // Applying this patch is an explicit opt-in, even after an earlier decline.
            $action->configureThemeForProvider($path, new NullProgressReporter, optIn: true);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelThemePatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
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

    private function target(): ?string
    {
        return resolve(PanelProviderTargetResolver::class)->resolve($this->providerPath, $this->adoptDefault)[0] ?? null;
    }
}
