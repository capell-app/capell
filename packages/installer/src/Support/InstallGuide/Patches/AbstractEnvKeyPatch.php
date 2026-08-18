<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\EnvFileEditor;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use RuntimeException;
use Throwable;

/**
 * Sets (or confirms) one key in the project's .env file. Subclasses supply
 * the key, the value to set, and how to read an existing value as a
 * PatchStatus; this base owns the shared file-exists/probe-before-apply/
 * backup-and-save/error-wrap shape every .env key patch was re-deriving.
 */
abstract class AbstractEnvKeyPatch implements Patch
{
    public function __construct(private readonly ?string $path = null) {}

    abstract protected function key(): string;

    abstract protected function value(): string;

    abstract protected function decideStatus(?string $currentValue): PatchStatus;

    public function probe(): PatchStatus
    {
        $path = $this->path();

        if (! file_exists($path)) {
            return PatchStatus::Unsupported;
        }

        try {
            $editor = new EnvFileEditor($path);

            return $this->decideStatus($editor->get($this->key()));
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

        throw_unless(file_exists($path), RuntimeException::class, '.env file not found at: ' . $path);

        $status = $this->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException('Cannot apply patch when status is: ' . $status->value);
        }

        try {
            $editor = new EnvFileEditor($path);
            $editor->backup();
            $editor->set($this->key(), $this->value());
            $editor->save();
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply ' . class_basename(static::class) . ': ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function path(): string
    {
        return $this->path ?? base_path('.env');
    }
}
