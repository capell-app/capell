<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Installer\Support\InstallGuide\Patch;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use Capell\Installer\Support\Patching\ConfigArrayEditor;
use Capell\Installer\Support\Patching\PhpFileEditor;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

class LoggingCapellChannelPatch implements Patch
{
    private const string CONFIG_FILE_PATH = 'config/logging.php';

    public function id(): string
    {
        return 'logging-capell-channel-patch';
    }

    public function group(): string
    {
        return 'config';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.logging_capell_channel_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.logging_capell_channel_patch_description');
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
        $configFilePath = base_path(self::CONFIG_FILE_PATH);

        if (! file_exists($configFilePath)) {
            return PatchStatus::Unsupported;
        }

        try {
            $editor = new PhpFileEditor($configFilePath);
            $configEditor = new ConfigArrayEditor($editor);

            // Check if capell channel already exists
            if (! $configEditor->hasKey('channels.capell')) {
                return PatchStatus::Applicable;
            }

            // capell exists; check if it matches canonical values
            if ($this->isCanonical()) {
                return PatchStatus::AlreadyApplied;
            }

            // capell exists but is customized
            return PatchStatus::Customised;
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
        $configFilePath = base_path(self::CONFIG_FILE_PATH);

        throw_unless(
            file_exists($configFilePath),
            RuntimeException::class,
            'config/logging.php not found at: ' . $configFilePath,
        );

        $status = $this->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(
                'Cannot apply patch when status is: ' . $status->value,
            );
        }

        try {
            $editor = new PhpFileEditor($configFilePath);
            $editor->backup();
            $configEditor = new ConfigArrayEditor($editor);

            // Build the capell channel array with canonical values
            $capellChannel = $this->buildCapellChannelArray();

            // Insert into channels as the first element
            $configEditor->insertKey('channels.capell', $capellChannel);

            $editor->save();
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply LoggingCapellChannelPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * Build the canonical capell channel array.
     */
    private function buildCapellChannelArray(): Array_
    {
        $items = [];

        // 'driver' => 'single'
        $items[] = new ArrayItem(
            new String_('single'),
            new String_('driver'),
            false,
            [],
        );

        // 'path' => storage_path('logs/capell.log')
        $storagePathCall = new FuncCall(
            new Name('storage_path'),
            [new Arg(new String_('logs/capell.log'))],
        );
        $items[] = new ArrayItem(
            $storagePathCall,
            new String_('path'),
            false,
            [],
        );

        // 'level' => 'debug'
        $items[] = new ArrayItem(
            new String_('debug'),
            new String_('level'),
            false,
            [],
        );

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * Check if the existing capell channel matches the canonical values.
     */
    private function isCanonical(): bool
    {
        try {
            $configFilePath = base_path(self::CONFIG_FILE_PATH);
            $content = file_get_contents($configFilePath);

            // Simple heuristic: check if the file contains canonical config values
            return str_contains($content, "'capell'")
                && str_contains($content, "'driver' => 'single'")
                && str_contains($content, "storage_path('logs/capell.log')")
                && str_contains($content, "'level' => 'debug'");
        } catch (Throwable) {
            return false;
        }
    }
}
