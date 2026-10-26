<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Illuminate\Foundation\Application;
use Override;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use RuntimeException;
use Throwable;

final class RuntimeRoleBootstrapPatch implements ReviewableFilePatch
{
    use PlansFilePatch;

    private const string BOOTSTRAP_PATH = 'bootstrap/app.php';

    #[Override]
    public function id(): string
    {
        return 'runtime-role-bootstrap-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'application';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.runtime_role_bootstrap_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.runtime_role_bootstrap_patch_description');
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
        $path = base_path(self::BOOTSTRAP_PATH);

        if (! is_file($path)) {
            return PatchStatus::Unsupported;
        }

        try {
            $editor = new PhpFileEditor($path);

            if (str_contains($editor->originalContent(), 'RuntimeRoleBootstrap::configure(')) {
                return PatchStatus::AlreadyApplied;
            }

            return $this->stockApplicationReturnIndex($editor) === null
                ? PatchStatus::Customised
                : PatchStatus::Applicable;
        } catch (RuntimeException|Throwable) {
            return PatchStatus::Unsupported;
        }
    }

    #[Override]
    public function reason(): ?string
    {
        return null;
    }

    protected function plan(): FilePatchPlan
    {
        $path = base_path(self::BOOTSTRAP_PATH);
        $editor = new PhpFileEditor($path);
        $returnIndex = $this->stockApplicationReturnIndex($editor);

        throw_if($returnIndex === null, RuntimeException::class, 'Could not find the stock Laravel application return statement.');

        $ast = $editor->getAst();
        $return = $ast[$returnIndex];

        throw_unless($return instanceof Return_ && $return->expr instanceof Expr, RuntimeException::class, 'Could not read the stock Laravel application return statement.');

        $application = new Variable('app');
        array_splice($ast, $returnIndex, 1, [
            new Expression(new Assign($application, $return->expr)),
            new Expression(new StaticCall(
                new Name('RuntimeRoleBootstrap'),
                new Identifier('configure'),
                [new Arg(new Variable('app'))],
            )),
            new Return_(new Variable('app')),
        ]);

        $editor->setAst($ast)->addUseStatements([RuntimeRoleBootstrap::class]);

        return new FilePatchPlan($path, self::BOOTSTRAP_PATH, $editor->originalContent(), $editor->print());
    }

    private function stockApplicationReturnIndex(PhpFileEditor $editor): ?int
    {
        foreach ($editor->getAst() as $index => $statement) {
            if (! $statement instanceof Return_) {
                continue;
            }

            if (! $statement->expr instanceof MethodCall) {
                continue;
            }

            if ($this->isStockApplicationCreateCall($statement->expr, $editor)) {
                return $index;
            }
        }

        return null;
    }

    private function isStockApplicationCreateCall(MethodCall $call, PhpFileEditor $editor): bool
    {
        if (! $call->name instanceof Identifier || $call->name->toString() !== 'create') {
            return false;
        }

        $expression = $call->var;

        while ($expression instanceof MethodCall) {
            $expression = $expression->var;
        }

        if (! $expression instanceof StaticCall
            || ! $expression->class instanceof Name
            || ! $expression->name instanceof Identifier
            || $expression->name->toString() !== 'configure') {
            return false;
        }

        $applicationClass = $expression->class->toString();

        if ($applicationClass === Application::class) {
            return true;
        }

        if ($applicationClass !== 'Application') {
            return false;
        }

        foreach ($editor->getAst() as $statement) {
            if (! $statement instanceof Use_) {
                continue;
            }

            if ($statement->type !== Use_::TYPE_NORMAL) {
                continue;
            }

            foreach ($statement->uses as $use) {
                $alias = $use->alias?->toString() ?? $use->name->getLast();

                if ($alias === 'Application' && $use->name->toString() === Application::class) {
                    return true;
                }
            }
        }

        return false;
    }
}
