<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
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

    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

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

    private function decide(Node $stmt): PatchStatus
    {
        if ($this->patcher->hasMethodCall($stmt, 'widgets')) {
            return PatchStatus::AlreadyApplied;
        }

        return PatchStatus::Applicable;
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

        $this->patcher->appendMethodCall($stmt, 'widgets', [new Arg($widgetArray)]);
    }
}
