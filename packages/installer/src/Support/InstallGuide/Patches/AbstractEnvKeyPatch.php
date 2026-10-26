<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\EnvFileEditor;
use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Override;
use RuntimeException;
use Throwable;

/**
 * Sets (or confirms) one key in the project's .env file. Subclasses supply
 * the key, the value to set, and how to read an existing value as a
 * PatchStatus; this base owns the shared file-exists/probe-before-apply/
 * backup-and-save/error-wrap shape every .env key patch was re-deriving.
 */
abstract class AbstractEnvKeyPatch implements ReviewableFilePatch
{
    use PlansFilePatch;

    public function __construct(private readonly ?string $path = null) {}

    abstract protected function key(): string;

    abstract protected function value(): string;

    abstract protected function decideStatus(?string $currentValue): PatchStatus;

    #[Override]
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

    #[Override]
    public function reason(): ?string
    {
        return null;
    }

    protected function plan(): FilePatchPlan
    {
        $path = $this->path();
        $before = file_get_contents($path);
        throw_unless(is_string($before), RuntimeException::class, 'Could not read environment file.');
        $editor = new EnvFileEditor($path);
        $editor->set($this->key(), $this->value());

        return new FilePatchPlan(
            $path,
            '.env',
            $before,
            $editor->content(),
            environment: true,
            visibleEnvironmentValues: match ($this->key()) {
                'QUEUE_CONNECTION' => ['QUEUE_CONNECTION' => ['sync', 'database']],
                'SETTINGS_CACHE_ENABLED' => ['SETTINGS_CACHE_ENABLED' => ['true', 'false']],
                default => [],
            },
        );
    }

    private function path(): string
    {
        return $this->path ?? base_path('.env');
    }
}
