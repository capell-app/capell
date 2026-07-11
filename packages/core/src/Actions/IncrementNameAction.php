<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Lorisleiva\Actions\Action;

/**
 * Increments a trailing number in a string, or appends one if absent.
 */
class IncrementNameAction extends Action
{
    public function handle(string $name, string $separator = ' '): string
    {
        // Match ' (n)' at the end, where n is a number
        if (preg_match('/^(.*) \((\d+)\)$/', $name, $matches)) {
            $base = $matches[1];
            $num = (int) $matches[2] + 1;

            return sprintf('%s (%d)', $base, $num);
        }

        // If name ends with a number but not in parentheses, treat as new
        if (preg_match('/^(.*?)(\d+)$/', $name, $matches)) {
            return sprintf('%s (%d)', trim($matches[1]), ((int) $matches[2]) + 1);
        }

        // Default: add ' (2)'
        return $name . ' (2)';
    }
}
