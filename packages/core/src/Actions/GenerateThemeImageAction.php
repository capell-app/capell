<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Theme;
use GdImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static void dispatch(int $themeId, string $signature)
 * @method static void run(int $themeId, string $signature)
 */
class GenerateThemeImageAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function handle(int $themeId, string $signature): void
    {
        $theme = Theme::query()->find($themeId);

        if (! $theme instanceof Theme || $this->isStale($theme, $signature)) {
            return;
        }

        try {
            $path = $this->writeImage($theme, $signature);
            $this->markReady($theme, $signature, $path);
        } catch (Throwable $throwable) {
            Log::warning('Generated theme image failed.', [
                'theme_id' => $themeId,
                'exception' => $throwable,
            ]);

            $this->markFailed($theme, $signature, $throwable->getMessage());
        }
    }

    private function writeImage(Theme $theme, string $signature): string
    {
        $image = imagecreatetruecolor($this->size(), $this->size());

        throw_if($image === false, RuntimeException::class, 'Unable to create image canvas.');

        imageantialias($image, true);

        $colors = $this->themeColors($theme);
        $background = $colors[0] ?? '#111827';

        imagefilledrectangle($image, 0, 0, $this->size(), $this->size(), $this->allocate($image, $background));
        $this->drawColorBlocks($image, $colors);
        $this->drawIcon($image, $theme, $this->contrastColor($background));

        $path = sprintf('theme-previews/%s-%s.png', $theme->getKey(), substr($signature, 0, 16));
        $absolutePath = Storage::disk('public')->path($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory [%s].', $directory));
        }

        imagepng($image, $absolutePath, 9);
        imagedestroy($image);

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function themeColors(Theme $theme): array
    {
        /** @var Collection<string, string> $colors */
        $colors = collect($theme->colors)
            ->map(fn (mixed $color): ?string => is_string($color) ? $this->normalizeColor($color) : null)
            ->filter();

        return collect([
            $colors->get('primary'),
            $colors->get('secondary'),
            $colors->reject(fn (string $color, string $name): bool => in_array($name, ['primary', 'secondary'], true))->first(),
        ])
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $colors
     */
    private function drawColorBlocks(GdImage $image, array $colors): void
    {
        if ($colors === []) {
            return;
        }

        if (count($colors) === 1) {
            imagefilledrectangle($image, 0, 0, $this->size(), $this->size(), $this->allocate($image, $colors[0]));

            return;
        }

        $primaryWidth = 800;
        imagefilledrectangle($image, 0, 0, $primaryWidth, $this->size(), $this->allocate($image, $colors[0]));
        imagefilledrectangle($image, $primaryWidth, 0, $this->size(), $this->size(), $this->allocate($image, $colors[1] ?? $colors[0]));

        if (isset($colors[2])) {
            imagefilledrectangle($image, $primaryWidth, 600, $this->size(), $this->size(), $this->allocate($image, $colors[2]));
        }
    }

    /** @return int<1, max> */
    private function size(): int
    {
        return 1200;
    }

    private function normalizeColor(string $color): ?string
    {
        if (preg_match('/^#(?:[0-9a-f]{3}){1,2}$/i', $color) === 1) {
            if (strlen($color) === 4) {
                return sprintf('#%s%s%s%s%s%s', $color[1], $color[1], $color[2], $color[2], $color[3], $color[3]);
            }

            return strtolower($color);
        }

        if (preg_match('/^rgb\((\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\)$/', $color, $matches) !== 1) {
            return null;
        }

        return sprintf('#%02x%02x%02x', min((int) $matches[1], 255), min((int) $matches[2], 255), min((int) $matches[3], 255));
    }

    private function allocate(GdImage $image, string $color, int $alpha = 0): int
    {
        [$red, $green, $blue] = $this->rgbFromHex($color);

        $allocatedColor = imagecolorallocatealpha($image, $red, $green, $blue, $this->alpha($alpha));

        throw_if($allocatedColor === false, RuntimeException::class, sprintf('Unable to allocate image color [%s].', $color));

        return $allocatedColor;
    }

    private function contrastColor(string $background): string
    {
        [$red, $green, $blue] = $this->rgbFromHex($background);
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance > 145 ? '#111827' : '#ffffff';
    }

    /**
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    private function rgbFromHex(string $color): array
    {
        if (preg_match('/^#([0-9a-f]{6})$/i', $color, $matches) !== 1) {
            return [17, 24, 39];
        }

        return [
            $this->colorChannel(substr($matches[1], 0, 2)),
            $this->colorChannel(substr($matches[1], 2, 2)),
            $this->colorChannel(substr($matches[1], 4, 2)),
        ];
    }

    /** @return int<0, 255> */
    private function colorChannel(string $hex): int
    {
        $channel = (int) hexdec($hex);

        if ($channel < 0) {
            return 0;
        }

        if ($channel > 255) {
            return 255;
        }

        return $channel;
    }

    /** @return int<0, 127> */
    private function alpha(int $alpha): int
    {
        if ($alpha < 0) {
            return 0;
        }

        if ($alpha > 127) {
            return 127;
        }

        return $alpha;
    }

    private function drawIcon(GdImage $image, Theme $theme, string $color): void
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];
        $icon = is_string($admin['icon'] ?? null) && $admin['icon'] !== '' ? $admin['icon'] : 'theme';
        $label = collect(explode('-', $icon))
            ->reject(fn (string $part): bool => in_array($part, ['heroicon', 'o', 's'], true))
            ->map(fn (string $part): string => mb_substr($part, 0, 1))
            ->join('');

        $this->drawCenteredText($image, mb_strtoupper(mb_substr($label, 0, 3)), 190, $color, 400, 650);
    }

    private function drawCenteredText(GdImage $image, string $text, int $size, string $color, int $centerX, int $baselineY): void
    {
        $font = $this->fontPath();
        $text = mb_strimwidth($text, 0, 28, '');

        if ($font !== null) {
            $box = imagettfbbox($size, 0, $font, $text);

            if (is_array($box)) {
                $width = abs($box[4] - $box[0]);
                imagettftext($image, $size, 0, $centerX - (int) floor($width / 2), $baselineY, $this->allocate($image, $color), $font, $text);

                return;
            }
        }

        imagestring($image, 5, (int) ($centerX - ((strlen($text) * imagefontwidth(5)) / 2)), $baselineY, $text, $this->allocate($image, $color));
    }

    private function fontPath(): ?string
    {
        foreach ([
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function isStale(Theme $theme, string $signature): bool
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];

        return ($admin['generated_image_signature'] ?? null) !== $signature
            || ($admin['generated_image_status'] ?? null) !== 'pending';
    }

    private function markReady(Theme $theme, string $signature, string $path): void
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];

        if (($admin['generated_image_signature'] ?? null) !== $signature) {
            Storage::disk('public')->delete($path);

            return;
        }

        unset($admin['generated_image_error']);

        $admin['generated_image'] = $path;
        $admin['generated_image_status'] = 'ready';

        Theme::withoutEvents(function () use ($theme, $admin): void {
            $theme->forceFill(['admin' => $admin])->save();
        });
    }

    private function markFailed(Theme $theme, string $signature, string $message): void
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];

        if (($admin['generated_image_signature'] ?? null) !== $signature) {
            return;
        }

        unset($admin['generated_image']);

        $admin['generated_image_status'] = 'failed';
        $admin['generated_image_error'] = mb_substr($message, 0, 500);

        Theme::withoutEvents(function () use ($theme, $admin): void {
            $theme->forceFill(['admin' => $admin])->save();
        });
    }
}
