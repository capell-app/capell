<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use FilesystemIterator;
use HashContext;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use SplFileInfo;

/** @method static string run(string $path) */
final class HashExtensionContentsAction
{
    use AsFake;
    use AsObject;

    public function handle(string $path): string
    {
        $root = realpath($path);
        throw_if($root === false || ! is_dir($root), RuntimeException::class, 'Extension directory is not readable.');

        $context = hash_init('sha256');
        $this->hashDirectory($context, $root, '');

        return hash_final($context);
    }

    private function hashDirectory(HashContext $context, string $directory, string $prefix): void
    {
        $entries = [];
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO) as $entry) {
            throw_unless($entry instanceof SplFileInfo, RuntimeException::class, 'Extension contents contain an unsupported entry.');
            $name = $entry->getFilename();
            if (in_array($name, ['.git', '.hg', '.svn'], true)) {
                continue;
            }

            throw_if($entry->isLink(), RuntimeException::class, 'Extension contents cannot contain symbolic links.');

            throw_if(! $entry->isFile() && ! $entry->isDir(), RuntimeException::class, 'Extension contents contain an unsupported entry.');

            $entries[$name . ($entry->isDir() ? '/' : '')] = $entry->getPathname();
        }

        ksort($entries, SORT_STRING);
        foreach ($entries as $name => $path) {
            if (str_ends_with($name, '/')) {
                $this->hashDirectory($context, $path, $prefix . $name);

                continue;
            }

            $relative = $prefix . $name;
            $fileHash = hash_file('sha256', $path);
            throw_if($fileHash === false, RuntimeException::class, 'Extension file could not be read.');

            hash_update($context, strlen($relative) . ':' . $relative . ':' . $fileHash . "\n");
        }
    }
}
