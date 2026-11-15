<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Media;

use Capell\Core\Data\ResponsiveImageData;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Describes an image that is stored as a plain disk path (for example by a widget field)
 * rather than as a Media row, and creates the smaller derivatives its srcset points at.
 *
 * Derivatives are written next to the original under a fixed directory, named from the
 * path, size and modification time, so an edited image never serves a stale derivative and
 * an unchanged one is generated once. Anything that is not a readable JPEG, PNG or WebP
 * returns null so the caller can keep rendering the plain path.
 */
final class ResolveResponsiveImageAction
{
    use AsFake;
    use AsObject;

    public const string DERIVATIVE_DIRECTORY = 'capell-responsive';

    /** Widths a derivative may be made at; only those smaller than the original are used. */
    public const array WIDTHS = [480, 768, 1200, 1600];

    private const int MAX_SOURCE_BYTES = 15 * 1024 * 1024;

    private const int MAX_PIXELS = 40_000_000;

    /** @var array<int, array{extension: string, mime: string}> */
    private const array TYPES = [
        IMAGETYPE_JPEG => ['extension' => 'jpg', 'mime' => 'image/jpeg'],
        IMAGETYPE_PNG => ['extension' => 'png', 'mime' => 'image/png'],
        IMAGETYPE_WEBP => ['extension' => 'webp', 'mime' => 'image/webp'],
    ];

    public function handle(string $path, string $disk = 'public'): ?ResponsiveImageData
    {
        $path = $this->normalisePath($path);

        if ($path === null) {
            return null;
        }

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path) || $storage->size($path) > self::MAX_SOURCE_BYTES) {
                return null;
            }

            $contents = $storage->get($path);
            $info = is_string($contents) ? @getimagesizefromstring($contents) : false;

            if ($info === false || ! isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
                return null;
            }

            $width = $info[0];
            $height = $info[1];
            $src = $storage->url($path);
            $turned = $info[2] === IMAGETYPE_JPEG && $this->isTurnedByExif((string) $contents);

            if ($turned) {
                // Browsers rotate the original by its EXIF orientation; derivatives carry no EXIF, so they
                // would not match it. Report the displayed size and keep the single original.
                if ($this->exifOrientation((string) $contents) >= 5) {
                    [$width, $height] = [$height, $width];
                }

                return new ResponsiveImageData(src: $src, width: $width, height: $height);
            }

            $srcset = $this->buildSrcset($storage, $path, (string) $contents, $info[2], $width, $height, $src);

            return new ResponsiveImageData(src: $src, width: $width, height: $height, srcset: $srcset);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalisePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        if ($path === '' || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    private function buildSrcset(Filesystem $storage, string $path, string $contents, int $type, int $width, int $height, string $src): ?string
    {
        if (! function_exists('imagecreatefromstring') || $width * $height > self::MAX_PIXELS) {
            return null;
        }

        $widths = array_values(array_filter(self::WIDTHS, static fn (int $candidate): bool => $candidate < $width));

        if ($widths === []) {
            return null;
        }

        $extension = self::TYPES[$type]['extension'];
        $version = substr(hash('sha256', $path . '|' . $storage->size($path) . '|' . $storage->lastModified($path)), 0, 40);
        $entries = [];
        $source = null;

        foreach ($widths as $target) {
            $derivative = self::DERIVATIVE_DIRECTORY . '/' . $version . '-' . $target . '.' . $extension;

            if (! $storage->exists($derivative)) {
                $source ??= @imagecreatefromstring($contents);

                if ($source === false) {
                    return null;
                }

                $encoded = $this->encode($source, $type, $target, (int) round($height * $target / $width));

                if ($encoded === null || ! $storage->put($derivative, $encoded)) {
                    return null;
                }
            }

            $entries[] = $storage->url($derivative) . ' ' . $target . 'w';
        }

        $entries[] = $src . ' ' . $width . 'w';

        return implode(', ', $entries);
    }

    private function isTurnedByExif(string $contents): bool
    {
        return $this->exifOrientation($contents) > 1;
    }

    private function exifOrientation(string $contents): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) ? $orientation : 1;
    }

    private function encode(GdImage $source, int $type, int $width, int $height): ?string
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $scaled = imagecreatetruecolor($width, $height);

        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        }

        if (! imagecopyresampled($scaled, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))) {
            return null;
        }

        ob_start();

        $written = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($scaled, null, 82),
            IMAGETYPE_PNG => imagepng($scaled),
            default => imagewebp($scaled, null, 80),
        };

        $bytes = ob_get_clean();

        return $written && is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
