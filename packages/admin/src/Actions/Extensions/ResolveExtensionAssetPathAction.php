<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Core\Support\Manifest\CapellManifestData;
use finfo;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveExtensionAssetPathAction
{
    use AsFake;
    use AsObject;

    public const string CARD_IMAGE_PATH = 'docs/assets/marketplace/extension-card.jpg';

    public function handle(CapellManifestData $manifest, string $path): ?string
    {
        if ($manifest->installPath === null || ! $this->isImagePath($path)) {
            return null;
        }

        $declared = str_starts_with($manifest->name, 'capell-app/') && $path === self::CARD_IMAGE_PATH;

        foreach ($manifest->marketplaceScreenshots as $screenshot) {
            $declared = $declared || $path === $screenshot->path;
        }

        if (! $declared) {
            return null;
        }

        $packagePath = realpath($manifest->installPath);
        $assetPath = realpath($manifest->installPath . DIRECTORY_SEPARATOR . $path);

        if (
            ! is_string($packagePath)
            || ! is_string($assetPath)
            || ! str_starts_with($assetPath, $packagePath . DIRECTORY_SEPARATOR)
            || ! is_file($assetPath)
            || ! is_readable($assetPath)
            || ! $this->isImagePath(substr($assetPath, strlen($packagePath) + 1))
        ) {
            return null;
        }

        $size = filesize($assetPath);
        if ($size === false || $size > 10 * 1024 * 1024) {
            return null;
        }

        // A declared image suffix must not turn an arbitrary text file into an asset.
        $mimeType = new finfo(FILEINFO_MIME_TYPE)->file($assetPath);
        if (strtolower(pathinfo($assetPath, PATHINFO_EXTENSION)) === 'svg') {
            if ($mimeType !== 'image/svg+xml') {
                return null;
            }

            $contents = file_get_contents($assetPath);

            return is_string($contents)
                && preg_match('/<\s*(?:script|foreignObject)\b|\bon[\w:-]*\s*=|javascript\s*:/i', $contents) === 0
                ? $assetPath
                : null;
        }

        if (! in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif'], true)) {
            return null;
        }

        return @getimagesize($assetPath) !== false ? $assetPath : null;
    }

    private function isImagePath(string $path): bool
    {
        if ($path === '' || trim($path) !== $path || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.') || str_contains($segment, ':')) {
                return false;
            }
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'svg'], true);
    }
}
