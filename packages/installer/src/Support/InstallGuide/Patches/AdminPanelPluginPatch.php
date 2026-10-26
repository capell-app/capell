<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

class AdminPanelPluginPatch implements ReviewableFilePatch
{
    use PlansFilePatch;

    public function __construct(
        private readonly AdminPanelProviderPatcher $patcher = new AdminPanelProviderPatcher,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'admin-panel-plugin-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'providers';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.admin_panel_plugin_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.admin_panel_plugin_patch_description');
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
                $this->injectDefaultCall($stmt);
                $this->injectPluginCall($stmt);
            },
            [CapellAdminPlugin::class],
        );
    }

    private function decide(Node $stmt): PatchStatus
    {
        if ($this->hasCapellAdminPluginCall($stmt) && $this->patcher->hasMethodCall($stmt, 'default')) {
            return PatchStatus::AlreadyApplied;
        }

        return PatchStatus::Applicable;
    }

    /**
     * Inject ->plugin(CapellAdminPlugin::make()->discoverConfigurators(...)) at the end of the chain.
     */
    private function injectPluginCall(Node $stmt): void
    {
        if ($this->hasCapellAdminPluginCall($stmt)) {
            return;
        }

        $appPathCall = new FuncCall(
            new Name('app_path'),
            [
                new Arg(
                    new String_('Filament/Configurators'),
                ),
            ],
        );

        $discoverConfiguratorsCall = new MethodCall(
            new StaticCall(
                new Name('CapellAdminPlugin'),
                'make',
            ),
            'discoverConfigurators',
            [
                new Arg(
                    $appPathCall,
                    false,
                    false,
                    [],
                    new Identifier('in'),
                ),
                new Arg(
                    new String_('App\\Filament\\Configurators'),
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
                new Arg($discoverConfiguratorsCall),
            ],
        );
    }

    private function hasCapellAdminPluginCall(Node $stmt): bool
    {
        if (! property_exists($stmt, 'expr') || ! $stmt->expr instanceof MethodCall) {
            return false;
        }

        $methodCall = $stmt->expr;

        while ($methodCall instanceof MethodCall) {
            $argument = $methodCall->args[0] ?? null;

            if ($methodCall->name instanceof Identifier
                && $methodCall->name->name === 'plugin'
                && $argument instanceof Arg
                && $this->usesCapellAdminPluginFactory($argument->value)) {
                return true;
            }

            $methodCall = $methodCall->var;
        }

        return false;
    }

    private function usesCapellAdminPluginFactory(Expr $expression): bool
    {
        while ($expression instanceof MethodCall) {
            $expression = $expression->var;
        }

        return $expression instanceof StaticCall
            && $expression->class instanceof Name
            && in_array($expression->class->toString(), ['CapellAdminPlugin', CapellAdminPlugin::class], true)
            && $expression->name instanceof Identifier
            && $expression->name->name === 'make';
    }

    private function injectDefaultCall(Node $stmt): void
    {
        if ($this->patcher->hasMethodCall($stmt, 'default')) {
            return;
        }

        $this->patcher->appendMethodCall($stmt, 'default');
    }
}
