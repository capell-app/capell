<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Illuminate\Support\Facades\Vite;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(string $asset, string $buildDirectory)
 */
class GetCriticalCssContentAction
{
    use AsObject;

    public function handle(string $asset, string $buildDirectory): string
    {
        return $this->sanitizeInlineCriticalCss(Vite::content($asset, $buildDirectory));
    }

    private function sanitizeInlineCriticalCss(string $css): string
    {
        $css = preg_replace(
            '/\s*\.\\\\@container,\s*\.\\\\\[container-type\\\\:inline-size\\\\\]\s*\{\s*container-type:\s*inline-size;\s*\}/',
            '',
            $css,
        ) ?? $css;

        return preg_replace(
            '/\s+and\s+\(not\s+\(margin-trim:\s*inline\)\)/',
            ' and (not (color:rgb(from red r g b)))',
            $css,
        ) ?? $css;
    }
}
