<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use RuntimeException;
use Throwable;

class AdminPanelColorsPatch implements Patch
{
    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

    public function id(): string
    {
        return 'admin-panel-colors-patch';
    }

    public function group(): string
    {
        return 'providers';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_colors_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_colors_patch_description');
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
                    $this->injectColorsCall($stmt);
                },
                [FilamentColorEnum::class],
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelColorsPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function decide(Node $stmt): PatchStatus
    {
        if ($this->patcher->hasMethodCall($stmt, 'colors')) {
            return PatchStatus::AlreadyApplied;
        }

        return PatchStatus::Applicable;
    }

    /**
     * Inject the ->colors(FilamentColorEnum::colors()) call after ->path(...).
     */
    private function injectColorsCall(Node $stmt): void
    {
        $this->patcher->insertMethodCallAfter(
            $stmt,
            'path',
            fn (MethodCall $call): MethodCall => new MethodCall($call, 'colors', [
                new Arg(new StaticCall(new Name('FilamentColorEnum'), 'colors')),
            ]),
        );
    }
}
