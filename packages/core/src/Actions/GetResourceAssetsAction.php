<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use FilesystemIterator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @method static array<int, string> run()
 */
class GetResourceAssetsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<int, string>
     */
    public function handle(): array
    {
        $assets = [];
        $resourcePath = resource_path();
        $assetTypes = [
            'css' => 'css',
            'js' => 'js',
        ];

        foreach ($assetTypes as $type => $subdir) {
            $assets = array_merge($assets, $this->findAssets($resourcePath, $subdir, $type));
        }

        return $assets;
    }

    /**
     * Recursively find asset files of a given type in a subdirectory.
     *
     * @return array<int, string>
     */
    private function findAssets(string $resourcePath, string $subdir, string $type): array
    {
        $found = [];

        $dir = $resourcePath . DIRECTORY_SEPARATOR . $subdir;
        if (! is_dir($dir)) {
            return $found;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== $type) {
                continue;
            }

            $filename = $file->getFilename();
            if (str_starts_with((string) $filename, '.')) {
                continue;
            }

            $relativePath = 'resources/' . $subdir . '/' . ltrim(str_replace($dir, '', $file->getPathname()), DIRECTORY_SEPARATOR);

            $found[] = $relativePath;
        }

        return $found;
    }
}
