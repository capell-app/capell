<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\ConfigArrayEditor;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

/**
 * Applies a guarded entry to one PHP configuration array.
 */
abstract class AbstractConfigArrayPatch implements Patch
{
    public function __construct(private readonly ?string $path = null) {}

    abstract protected function relativeConfigFilePath(): string;

    abstract protected function configArrayPath(): string;

    abstract protected function buildConfigValue(): Expr;

    abstract protected function isCanonicalValue(Expr $value): bool;

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

    public function reason(): ?string
    {
        return null;
    }

    public function apply(): void
    {
        $path = $this->path();

        throw_unless(
            file_exists($path),
            RuntimeException::class,
            $this->relativeConfigFilePath() . ' not found at: ' . $path,
        );

        $status = $this->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException('Cannot apply patch when status is: ' . $status->value);
        }

        try {
            $editor = new PhpFileEditor($path);
            $editor->backup();

            new ConfigArrayEditor($editor)->insertKey(
                $this->configArrayPath(),
                $this->buildConfigValue(),
            );

            $editor->save();
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply ' . class_basename(static::class) . ': ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
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
