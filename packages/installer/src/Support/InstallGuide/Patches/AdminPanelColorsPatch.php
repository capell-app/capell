<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;

class AdminPanelColorsPatch implements ReviewableFilePatch
{
    use PlansFilePatch;

    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'admin-panel-colors-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'providers';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_colors_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_colors_patch_description');
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
        return $this->patcher->probe($this->decide(...));
    }

    #[Override]
    public function reason(): ?string
    {
        return null;
    }

    protected function plan(): FilePatchPlan
    {
        return $this->patcher->plan(
            $this->decide(...),
            function (Node $stmt): void {
                $this->injectColorsCall($stmt);
            },
            [FilamentColorEnum::class],
        );
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
