<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Assets;

use Capell\Core\ThemeStudio\Typography\ThemeFontCatalogue;

final class ThemeTokenValidator
{
    /**
     * @param  array<string, string>  $tokens
     * @param  array<string, string>  $fallbackTokens
     * @return array<string, string>
     */
    public function sanitize(array $tokens, array $fallbackTokens = []): array
    {
        return collect($tokens)
            ->map(fn (string $value, string $token): string => $this->tokenValueIsSafe($token, $value) ? $value : ($fallbackTokens[$token] ?? 'initial'))
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function contrastIssues(string $foregroundColor, string $backgroundColor, string $label = 'foreground/background', float $minimumRatio = 4.5): array
    {
        $ratio = $this->contrastRatio($foregroundColor, $backgroundColor);

        if ($ratio === null) {
            return [sprintf('Contrast pair %s contains an invalid color token.', $label)];
        }

        if ($ratio >= $minimumRatio) {
            return [];
        }

        return [sprintf('Contrast pair %s ratio %.2f is below %.2f.', $label, $ratio, $minimumRatio)];
    }

    /**
     * The font keys in brand or preset values that are not in the font catalogue. An unknown key would otherwise
     * render as the catalogue's default face without anyone noticing, so definitions and editors refuse it.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function unknownFontKeys(array $values, ?string $typePairing = null): array
    {
        $keys = [];
        foreach (['headingFont', 'bodyFont'] as $field) {
            if (array_key_exists($field, $values)) {
                $keys[] = is_string($values[$field]) ? trim($values[$field]) : '';
            }
        }

        if ($typePairing !== null) {
            foreach (explode('+', $typePairing, 2) as $key) {
                $keys[] = trim($key);
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => ! ThemeFontCatalogue::has($key))));
    }

    public function safeCssValue(string $value): bool
    {
        return preg_match('/^[#(),.%\w\s\'"-]+$/', $value) === 1
            && ! str_contains(strtolower($value), 'url(')
            && ! str_contains(strtolower($value), 'expression(');
    }

    private function tokenValueIsSafe(string $token, string $value): bool
    {
        if (! $this->safeCssValue($value)) {
            return false;
        }

        if (! in_array($token, ['--theme-primary', '--theme-accent', '--theme-neutral', '--theme-surface', '--theme-foreground'], true)) {
            return true;
        }

        return $this->rgb($value) !== null;
    }

    private function contrastRatio(string $firstColor, string $secondColor): ?float
    {
        $first = $this->rgb($firstColor);
        $second = $this->rgb($secondColor);

        if ($first === null || $second === null) {
            return null;
        }

        $firstLuminance = $this->luminance($first);
        $secondLuminance = $this->luminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function rgb(string $color): ?array
    {
        $normalizedColor = ltrim(trim($color), '#');

        if (strlen($normalizedColor) === 3) {
            $normalizedColor = $normalizedColor[0] . $normalizedColor[0]
                . $normalizedColor[1] . $normalizedColor[1]
                . $normalizedColor[2] . $normalizedColor[2];
        }

        if (strlen($normalizedColor) !== 6 || ctype_xdigit($normalizedColor) === false) {
            return null;
        }

        return [
            (int) hexdec(substr($normalizedColor, 0, 2)),
            (int) hexdec(substr($normalizedColor, 2, 2)),
            (int) hexdec(substr($normalizedColor, 4, 2)),
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function luminance(array $rgb): float
    {
        $channels = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }
}
