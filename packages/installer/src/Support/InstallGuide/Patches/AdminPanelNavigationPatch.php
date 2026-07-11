<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Installer\Support\InstallGuide\Patch;
use Capell\Installer\Support\InstallGuide\Patches\Concerns\PatchesAdminPanelProvider;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use RuntimeException;
use Throwable;

class AdminPanelNavigationPatch implements Patch
{
    use PatchesAdminPanelProvider;

    private const string ADMIN_PANEL_PROVIDER_PATH = 'app/Providers/Filament/AdminPanelProvider.php';

    private const string CLASS_NAME = 'AdminPanelProvider';

    private const string PANEL_METHOD_NAME = 'panel';

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
        return $this->probePanelProvider(function (Node $stmt): PatchStatus {
            $hasNavigationItems = $this->hasNavigationItemsMethod($stmt);
            $hasNavigationGroups = $this->hasNavigationGroupsMethod($stmt);

            if ($hasNavigationItems && $hasNavigationGroups) {
                return PatchStatus::AlreadyApplied;
            }

            if (! $hasNavigationItems && ! $hasNavigationGroups) {
                return PatchStatus::Applicable;
            }

            return PatchStatus::Customised;
        });
    }

    public function reason(): ?string
    {
        return null;
    }

    public function apply(): void
    {
        try {
            $this->applyPanelProviderPatch(
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

    /**
     * Check if navigationItems() method is already present in the chain.
     */
    private function hasNavigationItemsMethod(Node $stmt): bool
    {
        return $this->hasMethodCall($stmt, 'navigationItems');
    }

    /**
     * Check if navigationGroups() method is already present in the chain.
     */
    private function hasNavigationGroupsMethod(Node $stmt): bool
    {
        return $this->hasMethodCall($stmt, 'navigationGroups');
    }

    /**
     * Inject ->navigationItems() and ->navigationGroups() calls after ->path(...).
     */
    private function injectNavigationCalls(Node $stmt): void
    {
        $this->insertMethodCallAfter(
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
