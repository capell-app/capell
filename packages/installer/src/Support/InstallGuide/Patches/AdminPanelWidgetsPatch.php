<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Installer\Support\InstallGuide\Patch;
use Capell\Installer\Support\InstallGuide\Patches\Concerns\PatchesAdminPanelProvider;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use RuntimeException;
use Throwable;

class AdminPanelWidgetsPatch implements Patch
{
    use PatchesAdminPanelProvider;

    private const string ADMIN_PANEL_PROVIDER_PATH = 'app/Providers/Filament/AdminPanelProvider.php';

    private const string CLASS_NAME = 'AdminPanelProvider';

    private const string PANEL_METHOD_NAME = 'panel';

    /**
     * The widget classes to inject into the chain.
     *
     * @var array<string>
     */
    private const array WIDGET_CLASSES = [
        ListPagesFilamentWidget::class,
        MyWorkQueueFilamentWidget::class,
        RecentlyPublishedFilamentWidget::class,
    ];

    public function id(): string
    {
        return 'admin-panel-widgets-patch';
    }

    public function group(): string
    {
        return 'providers';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_widgets_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_widgets_patch_description');
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
            if ($this->hasMethodCall($stmt, 'widgets')) {
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
            $this->applyPanelProviderPatch(
                function (Node $stmt): void {
                    $this->injectWidgetsCall($stmt);
                },
                self::WIDGET_CLASSES,
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelWidgetsPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * Inject ->widgets([...]) call at the end of the chain.
     */
    private function injectWidgetsCall(Node $stmt): void
    {
        $widgetItems = [];
        foreach (self::WIDGET_CLASSES as $widgetClass) {
            $className = class_basename($widgetClass);
            $widgetItems[] = new ArrayItem(
                new ClassConstFetch(
                    new Name($className),
                    'class',
                ),
            );
        }

        $widgetArray = new Array_($widgetItems);

        $this->appendMethodCall($stmt, 'widgets', [new Arg($widgetArray)]);
    }
}
