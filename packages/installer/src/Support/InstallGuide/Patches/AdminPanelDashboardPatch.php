<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Filament\Pages\Dashboard;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use RuntimeException;
use Throwable;

class AdminPanelDashboardPatch implements Patch
{
    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

    public function id(): string
    {
        return 'admin-panel-dashboard-patch';
    }

    public function group(): string
    {
        return 'providers';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_dashboard_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_dashboard_patch_description');
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
            $this->patcher->apply(
                $this->decide(...),
                function (Node $stmt): void {
                    $this->replaceDashboardPage($stmt);
                },
                [CapellDashboard::class],
                [Dashboard::class],
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelDashboardPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function decide(Node $stmt): PatchStatus
    {
        if ($this->hasDashboardPage($stmt, 'CapellDashboard')) {
            return PatchStatus::AlreadyApplied;
        }

        return $this->hasDashboardPage($stmt, 'Dashboard')
            ? PatchStatus::Applicable
            : PatchStatus::Customised;
    }

    private function hasDashboardPage(Node $stmt, string $className): bool
    {
        $pagesCall = $this->patcher->findMethodCall($stmt, 'pages');

        return $pagesCall instanceof MethodCall
            && $this->findClassConstFetchInPagesCall($pagesCall, $className) instanceof ClassConstFetch;
    }

    private function replaceDashboardPage(Node $stmt): void
    {
        $pagesCall = $this->patcher->findMethodCall($stmt, 'pages');

        if (! $pagesCall instanceof MethodCall) {
            return;
        }

        $dashboardFetch = $this->findClassConstFetchInPagesCall($pagesCall, 'Dashboard');

        if (! $dashboardFetch instanceof ClassConstFetch) {
            return;
        }

        $dashboardFetch->class = new Name('CapellDashboard');
    }

    private function findClassConstFetchInPagesCall(MethodCall $pagesCall, string $className): ?ClassConstFetch
    {
        $pagesArg = $pagesCall->args[0]->value ?? null;

        if (! $pagesArg instanceof Array_) {
            return null;
        }

        foreach ($pagesArg->items as $item) {
            if ($item?->value instanceof ClassConstFetch
                && $item->value->class instanceof Name
                && $item->value->class->toString() === $className) {
                return $item->value;
            }
        }

        return null;
    }
}
