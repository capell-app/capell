<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

class AdminPanelPluginPatch implements Patch
{
    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

    public function id(): string
    {
        return 'admin-panel-plugin-patch';
    }

    public function group(): string
    {
        return 'providers';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_plugin_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_plugin_patch_description');
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
                    $this->injectDefaultCall($stmt);
                    $this->injectPluginCall($stmt);
                },
                [CapellAdminPlugin::class],
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply AdminPanelPluginPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function decide(Node $stmt): PatchStatus
    {
        if ($this->patcher->hasMethodCall($stmt, 'plugin') && $this->patcher->hasMethodCall($stmt, 'default')) {
            return PatchStatus::AlreadyApplied;
        }

        return PatchStatus::Applicable;
    }

    /**
     * Inject ->plugin(CapellAdminPlugin::make()->discoverSchemas(...)) at the end of the chain.
     */
    private function injectPluginCall(Node $stmt): void
    {
        if ($this->patcher->hasMethodCall($stmt, 'plugin')) {
            return;
        }

        $appPathCall = new FuncCall(
            new Name('app_path'),
            [
                new Arg(
                    new String_('Filament/FormBuilder'),
                ),
            ],
        );

        $discoverSchemasCall = new MethodCall(
            new StaticCall(
                new Name('CapellAdminPlugin'),
                'make',
            ),
            'discoverSchemas',
            [
                new Arg(
                    $appPathCall,
                    false,
                    false,
                    [],
                    new Identifier('in'),
                ),
                new Arg(
                    new String_('App\\\\Filament\\\\FormBuilder'),
                    false,
                    false,
                    [],
                    new Identifier('for'),
                ),
            ],
        );

        $this->patcher->appendMethodCall(
            $stmt,
            'plugin',
            [
                new Arg($discoverSchemasCall),
            ],
        );
    }

    private function injectDefaultCall(Node $stmt): void
    {
        if ($this->patcher->hasMethodCall($stmt, 'default')) {
            return;
        }

        $this->patcher->appendMethodCall($stmt, 'default');
    }
}
