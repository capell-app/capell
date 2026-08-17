<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use RuntimeException;
use Throwable;

class AdminPanelNavigationPatch implements Patch
{
    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

    public function id(): string
    {
        return 'admin-panel-navigation-patch';
    }

    public function group(): string
    {
        return 'providers';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_navigation_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_navigation_patch_description');
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
                    $this->injectNavigationCalls($stmt);
                },
                [CapellAdmin::class],
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelNavigationPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function decide(Node $stmt): PatchStatus
    {
        $hasNavigationItems = $this->hasNavigationItemsMethod($stmt);
        $hasNavigationGroups = $this->hasNavigationGroupsMethod($stmt);

        if ($hasNavigationItems && $hasNavigationGroups) {
            return PatchStatus::AlreadyApplied;
        }

        if (! $hasNavigationItems && ! $hasNavigationGroups) {
            return PatchStatus::Applicable;
        }

        return PatchStatus::Customised;
    }

    /**
     * Check if navigationItems() method is already present in the chain.
     */
    private function hasNavigationItemsMethod(Node $stmt): bool
    {
        return $this->patcher->hasMethodCall($stmt, 'navigationItems');
    }

    /**
     * Check if navigationGroups() method is already present in the chain.
     */
    private function hasNavigationGroupsMethod(Node $stmt): bool
    {
        return $this->patcher->hasMethodCall($stmt, 'navigationGroups');
    }

    /**
     * Inject ->navigationItems() and ->navigationGroups() calls after ->path(...).
     */
    private function injectNavigationCalls(Node $stmt): void
    {
        $this->patcher->insertMethodCallAfter(
            $stmt,
            'path',
            fn (MethodCall $call): MethodCall => new MethodCall(
                new MethodCall($call, 'navigationItems', [
                    new Arg(new StaticCall(new Name('CapellAdmin'), 'getNavigationItems')),
                ]),
                'navigationGroups',
                [new Arg(new StaticCall(new Name('CapellAdmin'), 'getNavigationGroups'))],
            ),
        );
    }
}
