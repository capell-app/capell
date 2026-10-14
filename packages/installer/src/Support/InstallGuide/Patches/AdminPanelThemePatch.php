<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Install\PanelThemeTargetResolver;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use Override;
use RuntimeException;
use Throwable;

class AdminPanelThemePatch implements Patch
{
    public function __construct(
        private readonly ?string $providerPath = null,
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
        $paths = resolve(PanelThemeTargetResolver::class)->resolve($this->providerPath);

        if ($paths === []) {
            return PatchStatus::Unsupported;
        }

        $action = resolve(InstallFilamentPanelAction::class);
        $statuses = array_map($action->themePatchStatus(...), $paths);

        foreach ([PatchStatus::Unsupported, PatchStatus::Customised, PatchStatus::Applicable] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return PatchStatus::AlreadyApplied;
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
            $status = $this->probe();
            throw_unless($status === PatchStatus::Applicable, RuntimeException::class, 'Cannot apply patch when status is: ' . $status->value);

            $action = resolve(InstallFilamentPanelAction::class);

            foreach (resolve(PanelThemeTargetResolver::class)->resolve($this->providerPath) as $path) {
                if ($action->themePatchStatus($path) === PatchStatus::Applicable) {
                    new PhpFileEditor($path)->backup();
                    $action->configureThemeForProvider($path, new NullProgressReporter);
                }
            }
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelThemePatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }
}
