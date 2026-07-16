<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array{r: int, g: int, b: int}|string|null run(string|array<string, mixed> $color, array<string, mixed> $override = [], bool $extract = false)
 */
class UpdateRgbColorAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  string|array<string, mixed>  $color
     * @param  array<string, mixed>  $override
     * @return array{r: int, g: int, b: int}|string|null
     */
    public function handle(string|array $color, array $override = [], bool $extract = false): array|string|null
    {
        // If array, merge and clamp
        if (is_array($color)) {
            $merged = array_merge($color, $override);

            return [
                'r' => max(0, min(255, (int) ($merged['r'] ?? 0))),
                'g' => max(0, min(255, (int) ($merged['g'] ?? 0))),
                'b' => max(0, min(255, (int) ($merged['b'] ?? 0))),
            ];
        }

        if ($color === '' || $color === '0') {
            return null;
        }

        if ($extract) {
            return $this->extractColor($color);
        }

        return $this->buildColorString($color);
    }

    private function extractColor(string $color): string
    {
        return preg_replace_callback(
            '/^rgba?\((\d{1,3}), (\d{1,3}), (\d{1,3})(?:, ([\d.]+))?\)$/',
            function (array $matches): string {
                $red = $matches[1];
                $green = $matches[2];
                $blue = $matches[3];
                $alpha = $matches[4] ?? null;

                return $alpha !== null
                    ? sprintf('%s,%s,%s,%s', $red, $green, $blue, $alpha)
                    : sprintf('%s,%s,%s', $red, $green, $blue);
            },
            $color,
        ) ?? $color;
    }

    private function buildColorString(string $color): string
    {
        if (preg_match('/^rgba?\s*\(/i', $color)) {
            return $color;
        }

        $components = array_map(trim(...), explode(',', mb_trim($color)));

        return count($components) === 4
            ? 'rgba(' . implode(', ', $components) . ')'
            : 'rgb(' . implode(', ', $components) . ')';
    }
}
