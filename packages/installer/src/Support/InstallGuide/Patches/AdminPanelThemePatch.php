<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Installer\Support\InstallGuide\Patch;
use Capell\Installer\Support\InstallGuide\Patches\Concerns\PatchesAdminPanelProvider;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

class AdminPanelThemePatch implements Patch
{
    use PatchesAdminPanelProvider;

    private const string ADMIN_PANEL_PROVIDER_PATH = 'app/Providers/Filament/AdminPanelProvider.php';

    private const string CLASS_NAME = 'AdminPanelProvider';

    private const string PANEL_METHOD_NAME = 'panel';

    private const string THEME_PATH = 'resources/css/filament/admin/theme.css';

    private const string THEME_BUILD_DIRECTORY = 'build/filament';

    public function id(): string
    {
        return 'admin-panel-theme-patch';
    }

    public function group(): string
    {
        return 'providers';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_theme_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_theme_patch_description');
    }

    public function docUrl(): ?string
    {
        return null;
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    public function probe(): PatchStatus
    {
        return $this->probePanelProvider(function (Node $stmt): PatchStatus {
            if ($this->hasThemeMethod($stmt)) {
                return PatchStatus::AlreadyApplied;
            }

            return PatchStatus::Applicable;
        });
    }

    public function reason(): ?string
    {
        return null;
    }

    public function apply(): void
    {
        try {
            $this->applyPanelProviderPatch(function (Node $stmt): void {
                $this->injectViteThemeCall($stmt);
            });
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelThemePatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function hasThemeMethod(Node $stmt): bool
    {
        if ($this->hasMethodCall($stmt, 'viteTheme')) {
            return true;
        }

        return $this->hasMethodCall($stmt, 'theme');
    }

    private function injectViteThemeCall(Node $stmt): void
    {
        if (! property_exists($stmt, 'expr') || ! $stmt->expr instanceof MethodCall) {
            return;
        }

        $stmt->expr = new MethodCall(
            $stmt->expr,
            'viteTheme',
            [
                new Arg(new String_(self::THEME_PATH)),
                new Arg(new String_(self::THEME_BUILD_DIRECTORY)),
            ],
        );
    }
}
