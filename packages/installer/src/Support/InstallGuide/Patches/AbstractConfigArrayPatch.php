<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\ConfigArrayEditor;
use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Override;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use Throwable;

/**
 * Applies a guarded entry to one PHP configuration array.
 */
abstract class AbstractConfigArrayPatch implements ReviewableFilePatch
{
    use PlansFilePatch;

    public function __construct(private readonly ?string $path = null) {}

    abstract protected function relativeConfigFilePath(): string;

    abstract protected function configArrayPath(): string;

    abstract protected function buildConfigValue(): Expr;

    abstract protected function isCanonicalValue(Expr $value): bool;

    #[Override]
    public function probe(): PatchStatus
    {
        $path = $this->path();

        if (! file_exists($path)) {
            return PatchStatus::Unsupported;
        }

        try {
            $configEditor = new ConfigArrayEditor(new PhpFileEditor($path));
            $value = $configEditor->findValue($this->configArrayPath());

            if (! $value instanceof Expr) {
                $rootKey = explode('.', $this->configArrayPath(), 2)[0];

                return $configEditor->findValue($rootKey) instanceof Array_
                    ? PatchStatus::Applicable
                    : PatchStatus::Unsupported;
            }

            return $this->isCanonicalValue($value)
                ? PatchStatus::AlreadyApplied
                : PatchStatus::Customised;
        } catch (Throwable) {
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
        $path = $this->path();
        $editor = new PhpFileEditor($path);
        $value = $this->buildConfigValue();
        new ConfigArrayEditor($editor)->insertKey($this->configArrayPath(), $value);
        $keys = explode('.', $this->configArrayPath());
        foreach (new NodeFinder()->findInstanceOf($value, ArrayItem::class) as $item) {
            if ($item->key instanceof String_) {
                $keys[] = $item->key->value;
            }
        }

        return new FilePatchPlan($path, $this->relativeConfigFilePath(), $editor->originalContent(), $editor->print(), visiblePhpArrayKeys: $keys);
    }

    protected function arrayItemValue(Array_ $array, string $key): ?Expr
    {
        $value = null;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key instanceof String_ && $item->key->value === $key) {
                // PHP array literals resolve duplicate keys to their final declaration.
                $value = $item->value;
            }
        }

        return $value;
    }

    protected function isFunctionCallWithStringArgument(?Expr $value, string $function, string $argument): bool
    {
        if (! $value instanceof FuncCall
            || ! $value->name instanceof Name
            || $value->name->toString() !== $function
            || count($value->args) !== 1
        ) {
            return false;
        }

        $functionArgument = $value->args[0];

        return $functionArgument instanceof Arg
            && $functionArgument->value instanceof String_
            && $functionArgument->value->value === $argument;
    }

    private function path(): string
    {
        return $this->path ?? base_path($this->relativeConfigFilePath());
    }
}
