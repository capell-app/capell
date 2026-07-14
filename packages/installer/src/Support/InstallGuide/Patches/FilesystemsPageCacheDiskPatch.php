<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\ConfigArrayEditor;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

class FilesystemsPageCacheDiskPatch implements Patch
{
    private const string CONFIG_FILE_PATH = 'config/filesystems.php';

    public function id(): string
    {
        return 'filesystems-page-cache-disk-patch';
    }

    public function group(): string
    {
        return 'config';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.filesystems_page_cache_disk_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.filesystems_page_cache_disk_patch_description');
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

            // Check if page_cache disk already exists
            if (! $configEditor->hasKey('disks.page_cache')) {
                return PatchStatus::Applicable;
            }

            // page_cache exists; check if it matches canonical values
            if ($this->isCanonical()) {
                return PatchStatus::AlreadyApplied;
            }

            // page_cache exists but is customized
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
            'config/filesystems.php not found at: ' . $configFilePath,
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

            // Build the page_cache array with canonical values
            $pageCacheArray = $this->buildPageCacheArray();

            // Insert into disks as the first element
            $configEditor->insertKey('disks.page_cache', $pageCacheArray);

            $editor->save();
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply FilesystemsPageCacheDiskPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * Build the canonical page_cache disk array.
     */
    private function buildPageCacheArray(): Array_
    {
        $items = [];

        // 'driver' => 'local'
        $items[] = new ArrayItem(
            new String_('local'),
            new String_('driver'),
            false,
            [],
        );

        // 'root' => public_path('page-cache')
        $publicPathCall = new FuncCall(
            new Name('public_path'),
            [new Arg(new String_('page-cache'))],
        );
        $items[] = new ArrayItem(
            $publicPathCall,
            new String_('root'),
            false,
            [],
        );

        // 'throw' => false
        $items[] = new ArrayItem(
            new ConstFetch(new Name('false')),
            new String_('throw'),
            false,
            [],
        );

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * Check if the existing page_cache disk matches the canonical values.
     */
    private function isCanonical(): bool
    {
        try {
            $configFilePath = base_path(self::CONFIG_FILE_PATH);
            $content = file_get_contents($configFilePath);

            // Simple heuristic: check if the file contains canonical config values
            return str_contains($content, "'page_cache'")
                && str_contains($content, "'driver' => 'local'")
                && str_contains($content, "public_path('page-cache')")
                && str_contains($content, "'throw' => false");
        } catch (Throwable) {
            return false;
        }
    }
}
