<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Closure;

final class AssertsPublicOutputSafety
{
    /** @param Closure(): string|null $render */
    public static function run(string $packageRoot, ?Closure $render): void
    {
        if (! $render instanceof Closure) {
            return;
        }

        $html = mb_strtolower($render());

        foreach (['wire:', 'filament', 'data-record-id', '/admin'] as $forbidden) {
            throw_if(str_contains($html, $forbidden), AssertionError::class, sprintf('[public-output.safety] %s: public output contains [%s].', $packageRoot, $forbidden));
        }
    }
}
