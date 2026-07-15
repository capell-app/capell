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
        if ($render === null) {
            return;
        }

        $html = mb_strtolower($render());

        foreach (['wire:', 'filament', 'data-record-id', '/admin'] as $forbidden) {
            if (str_contains($html, $forbidden)) {
                throw new AssertionError("[public-output.safety] {$packageRoot}: public output contains [{$forbidden}].");
            }
        }
    }
}
