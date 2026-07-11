<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ?string run(?string $color, string $format = 'rgba')
 */
class ColorConverterAction
{
    use AsObject;

    /**
     * Auto-detects color format (hex or rgb) and converts to hex, rgb(a), oklch, or hsl.
     *
     * @param  string  $color  Input color string (hex or rgb)
     * @param  string  $format  Output format: hex, rgb, oklch, hsl
     * @return string Converted color string
     *
     * @throws InvalidArgumentException
     */
    public function handle(?string $color, string $format = 'rgba'): ?string
    {
        if (in_array($color, [null, '', '0'], true)) {
            return null;
        }

        $cacheKey = 'color_converter:' . preg_replace('/[^a-z0-9_\-:|]/i', '_', strtolower(trim($color)) . '|' . strtolower($format));

        return Cache::store('array')->remember($cacheKey, 60, function () use ($color, $format): string {
            $rgb = $this->parseColor($color);

            // If output format is 'rgba', but alpha is not present, treat as opaque (alpha=1)
            if ($format === 'rgba' && ! isset($rgb['a'])) {
                $rgb['a'] = 1;
            }

            return match (strtolower($format)) {
                'hex' => $this->rgbToHex($rgb),
                'rgb' => $this->rgbToRgbString($rgb),
                'rgba' => $this->rgbToRgbaString($rgb),
                'hsl' => $this->rgbToHslString($rgb),
                'oklch' => $this->rgbToOklchString($rgb),
                default => throw new InvalidArgumentException('Unsupported output format: ' . $format),
            };
        });
    }

    /**
     * Detects and parses a color string to an RGB array.
     *
     * Supports:
     *  - Hex: #fff, #ffffff, fff, ffffff
     *  - rgb(): comma or space separated integers e.g. rgb(255, 0, 128) / rgb(255 0 128)
     *  - rgba(): with alpha ignored e.g. rgba(255,0,0,0.5)
     *  - New CSS4 syntax with slash alpha: rgb(255 0 0 / 50%), rgb(10 20 30 / 0.25)
     *  - Percent channel values: rgb(100% 0% 50%), rgb(100%,0%,50%)
     *
     * @return array{0: int, 1: int, 2: int, a?: float|int}
     *
     * @throws InvalidArgumentException
     */
    private function parseColor(string $color): array
    {
        $color = trim($color);
        $type = ColorTypeDetectorAction::run($color);

        switch ($type) {
            case 'hex':
                $hex = ltrim((string) preg_replace('/^#/', '', $color), '#');

                if (strlen($hex) === 3) {
                    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                }

                return [
                    (int) hexdec(substr($hex, 0, 2)),
                    (int) hexdec(substr($hex, 2, 2)),
                    (int) hexdec(substr($hex, 4, 2)),
                ];

            case 'rgba':
                $inside = trim((string) preg_replace('/^rgba?\\((.*)\\)$/i', '$1', $color));
                $alpha = null;

                if (str_contains($inside, '/')) {
                    [$inside, $alphaPart] = array_map(
                        trim(...),
                        explode('/', $inside, 2),
                    );
                    $alpha = $this->parseAlpha($alphaPart);
                }

                if (str_contains($inside, ',')) {
                    $parts = array_map(
                        trim(...),
                        explode(',', $inside),
                    );
                } else {
                    $inside = preg_replace('/\\s+/', ' ', $inside);
                    $parts = array_filter(
                        explode(' ', (string) $inside),
                        static fn (string $part): bool => $part !== '',
                    );
                }

                throw_if(count($parts) < 3, InvalidArgumentException::class, 'Invalid color format: ' . $color);

                $rgbChannels = array_slice($parts, 0, 3);
                /** @var array<int, int> $rgb */
                $rgb = [];

                foreach ($rgbChannels as $channel) {
                    if (preg_match('/^(\\d+(?:\\.\\d+)?)%$/', $channel, $percentMatch)) {
                        $percent = (float) $percentMatch[1];
                        throw_if($percent < 0 || $percent > 100, InvalidArgumentException::class, 'Invalid color format: ' . $color);
                        $rgb[] = (int) round($percent * 255 / 100);
                    } elseif (preg_match('/^(\\d{1,3})(?:\\.\\d+)?$/', $channel, $intMatch)) {
                        $value = (int) $intMatch[1];
                        throw_if($value < 0 || $value > 255, InvalidArgumentException::class, 'Invalid color format: ' . $color);
                        $rgb[] = $value;
                    } else {
                        throw new InvalidArgumentException('Invalid color format: ' . $color);
                    }
                }

                // If alpha is not set from slash, check for 4th channel (comma/space separated)
                if ($alpha === null && count($parts) > 3) {
                    $alpha = $this->parseAlpha($parts[3]);
                }

                // Attach alpha to rgb for downstream use
                if ($alpha !== null) {
                    $rgb['a'] = $alpha;
                }

                /** @var array{0: int, 1: int, 2: int, a?: float|int} $rgb */
                return $rgb;

            case 'rgb':
                if (preg_match('/^(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})$/', $color, $matches)) {
                    return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
                }

                throw new InvalidArgumentException('Invalid color format: ' . $color);
            default:
                throw new InvalidArgumentException('Invalid color format: ' . $color);
        }
    }

    /**
     * @param  array{0: int, 1: int, 2: int, a?: float|int}  $rgb
     */
    private function rgbToHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, a?: float|int}  $rgb
     */
    private function rgbToRgbString(array $rgb): string
    {
        return sprintf('rgb(%d, %d, %d)', $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, a?: float|int}  $rgb
     */
    private function rgbToRgbaString(array $rgb): string
    {
        $alpha = 1;
        if (isset($rgb['a'])) {
            $alpha = $rgb['a'];
        }

        // If alpha is 1 (or 1.0), return rgb(), else rgba()
        if (in_array($alpha, [1, '1', 1.0, '1.0'], true)) {
            return sprintf('rgb(%d, %d, %d)', $rgb[0], $rgb[1], $rgb[2]);
        }

        $formattedAlpha = ((float) $alpha) === 0.0
            ? '0'
            : rtrim(rtrim((string) $alpha, '0'), '.');

        return sprintf('rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], $formattedAlpha);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, a?: float|int}  $rgb
     */
    private function rgbToHslString(array $rgb): string
    {
        [$h, $s, $l] = $this->rgbToHsl($rgb[0], $rgb[1], $rgb[2]);

        return sprintf('hsl(%d, %.1f%%, %.1f%%)', round($h), $s * 100, $l * 100);
    }

    // Converts RGB to HSL, returns [h, s, l] where h in [0,360], s/l in [0,1]
    /**
     * @return array{0: float|int, 1: float|int, 2: float|int}
     */
    private function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $h = ($max + $min) / 2;
        $s = ($max + $min) / 2;
        $l = ($max + $min) / 2;
        if ($max === $min) {
            $h = 0;
            $s = 0;
            // achromatic
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            switch ($max) {
                case $r:
                    $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $h = ($b - $r) / $d + 2;
                    break;
                case $b:
                    $h = ($r - $g) / $d + 4;
                    break;
            }

            $h /= 6;
        }

        return [round($h * 360), $s, $l];
    }

    // Approximate RGB to OKLCH conversion (not perceptually accurate, but sufficient for basic use)
    /**
     * @param  array{0: int, 1: int, 2: int, a?: float|int}  $rgb
     */
    private function rgbToOklchString(array $rgb): string
    {
        // Convert to linear RGB
        $r = $rgb[0] / 255;
        $g = $rgb[1] / 255;
        $b = $rgb[2] / 255;
        // sRGB to XYZ
        $r = $r <= 0.04045 ? $r / 12.92 : (($r + 0.055) / 1.055) ** 2.4;
        $g = $g <= 0.04045 ? $g / 12.92 : (($g + 0.055) / 1.055) ** 2.4;
        $b = $b <= 0.04045 ? $b / 12.92 : (($b + 0.055) / 1.055) ** 2.4;
        $x = $r * 0.4124 + $g * 0.3576 + $b * 0.1805;
        $y = $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
        $z = $r * 0.0193 + $g * 0.1192 + $b * 0.9505;
        // XYZ to Lab
        $xn = 0.95047;
        $yn = 1.0;
        $zn = 1.08883;
        $fx = $this->labF($x / $xn);
        $fy = $this->labF($y / $yn);
        $fz = $this->labF($z / $zn);
        $l = 116 * $fy - 16;
        $a = 500 * ($fx - $fy);
        $b = 200 * ($fy - $fz);
        // Lab to LCH
        $c = sqrt($a * $a + $b * $b);
        $h = atan2($b, $a) * 180 / M_PI;
        if ($h < 0) {
            $h += 360;
        }

        // Lab to OKLab/OKLCH (approximate)
        // For real OKLCH, use a color library. Here, we just format as 'oklch(L C H)'
        return sprintf('oklch(%.2f%% %.4f %.2f)', $l, $c / 100, $h);
    }

    private function labF(float $t): float
    {
        return $t > (6 / 29) ** 3 ? $t ** (1 / 3) : (1 / 3) * (29 / 6) ** 2 * $t + 4 / 29;
    }

    /**
     * Parses alpha channel from string (percent or float)
     */
    private function parseAlpha(string $alpha): float|int
    {
        $alpha = trim($alpha);
        if (str_ends_with($alpha, '%')) {
            $percent = (float) rtrim($alpha, '%');

            return $percent / 100;
        }

        if (is_numeric($alpha)) {
            $float = (float) $alpha;
            if ($float < 0) {
                return 0;
            }

            if ($float > 1) {
                return 1;
            }

            return $float;
        }

        return 1;
    }
}
