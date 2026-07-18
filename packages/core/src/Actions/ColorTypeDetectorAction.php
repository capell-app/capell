<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Detects the color type from a string: hex, rgb, plain_rgb, or unknown.
 *
 * @method static string run(string $color)
 */
final class ColorTypeDetectorAction
{
    use AsFake;
    use AsObject;

    public function handle(string $color): string
    {
        $color = trim($color);

        if (preg_match('/^#?([a-f0-9]{3}|[a-f0-9]{6})$/i', $color)) {
            return 'hex';
        }

        if (preg_match('/^rgba?\((.*)\)$/i', $color)) {
            return 'rgba';
        }

        if (preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', $color)) {
            return 'rgb';
        }

        throw new InvalidArgumentException('Unable to detect color type for: ' . $color);
    }
}
