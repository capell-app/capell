<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

class AdminPanelThemePatch implements Patch
{
    private const string THEME_PATH = 'resources/css/filament/admin/theme.css';

    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

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
        return $this->patcher->probe($this->decide(...));
    }

    public function reason(): ?string
    {
        return null;
    }

    public function apply(): void
    {
        try {
            $this->patcher->apply($this->decide(...), function (Node $stmt): void {
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

    private function decide(Node $stmt): PatchStatus
    {
        if ($this->hasThemeMethod($stmt)) {
            return PatchStatus::AlreadyApplied;
        }

        return PatchStatus::Applicable;
    }

    private function hasThemeMethod(Node $stmt): bool
    {
        if ($this->patcher->hasMethodCall($stmt, 'viteTheme')) {
            return true;
        }

        return $this->patcher->hasMethodCall($stmt, 'theme');
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
            ],
        );
    }
}
