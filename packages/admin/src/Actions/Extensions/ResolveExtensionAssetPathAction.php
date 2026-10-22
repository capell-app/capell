<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Core\Support\Manifest\CapellManifestData;
use finfo;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveExtensionAssetPathAction
{
    use AsFake;
    use AsObject;

    public const string CARD_IMAGE_PATH = 'docs/assets/marketplace/extension-card.jpg';

    private const int MAX_DECODED_PIXELS = 8_000_000;

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
                && preg_match('/<\s*(?:script|foreignObject)\b|<!\s*(?:DOCTYPE|ENTITY)|\bon[\w:-]*\s*=|javascript\s*:/i', $contents) === 0
                ? $assetPath
                : null;
        }

        if (! in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif'], true)) {
            return null;
        }

        return $this->decodesAsRaster($assetPath, $mimeType) ? $assetPath : null;
    }

    /**
     * getimagesize() only reads headers, so a truncated file passes it. Small images are fully
     * decoded with GD; a decode of a very large image could exhaust the request's memory, so
     * those are checked for a complete container (final marker or declared length) instead.
     */
    private function decodesAsRaster(string $assetPath, string $mimeType): bool
    {
        $dimensions = @getimagesize($assetPath);

        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
            return false;
        }

        $contents = file_get_contents($assetPath);

        if (! is_string($contents)) {
            return false;
        }

        if ($dimensions[0] * $dimensions[1] <= self::MAX_DECODED_PIXELS) {
            return function_exists('imagecreatefromstring') && @imagecreatefromstring($contents) !== false;
        }

        $riffLength = unpack('V', substr($contents, 4, 4));

        return match ($mimeType) {
            'image/png' => str_ends_with($contents, "IEND\xAEB`\x82"),
            'image/jpeg' => str_ends_with(rtrim($contents, "\0"), "\xFF\xD9"),
            'image/gif' => str_ends_with($contents, ';'),
            'image/webp' => str_starts_with($contents, 'RIFF') && is_array($riffLength) && strlen($contents) === $riffLength[1] + 8,
            default => false,
        };
    }

    private function isImagePath(string $path): bool
    {
        if ($path === '' || Str::trim($path) !== $path || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
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
