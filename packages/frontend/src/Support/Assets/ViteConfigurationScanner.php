<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

/**
 * Finds places in a Vite configuration without being fooled by comments, strings or nested objects,
 * such as a PostCSS or Rollup `plugins` array that sits inside the exported configuration.
 */
final class ViteConfigurationScanner
{
    /** The source with comments and string contents blanked out, keeping every offset unchanged. */
    public static function code(string $source): string
    {
        $code = $source;
        $length = strlen($source);
        $index = 0;

        while ($index < $length) {
            $character = $source[$index];
            $next = $source[$index + 1] ?? '';

            if ($character === '/' && $next === '/') {
                $end = strpos($source, "\n", $index);
                $end = $end === false ? $length : $end;
                $code = substr_replace($code, str_repeat(' ', $end - $index), $index, $end - $index);
                $index = $end;

                continue;
            }

            if ($character === '/' && $next === '*') {
                $end = strpos($source, '*/', $index + 2);
                $end = $end === false ? $length : $end + 2;
                $code = substr_replace($code, preg_replace('/[^\n]/', ' ', substr($source, $index, $end - $index)) ?? '', $index, $end - $index);
                $index = $end;

                continue;
            }

            if (in_array($character, ['"', "'", '`'], true)) {
                $end = $index + 1;

                while ($end < $length && $source[$end] !== $character) {
                    $end += $source[$end] === '\\' ? 2 : 1;
                }

                $code = substr_replace($code, str_repeat(' ', max(0, min($end, $length) - $index - 1)), $index + 1, max(0, min($end, $length) - $index - 1));
                $index = $end + 1;

                continue;
            }

            $index++;
        }

        return $code;
    }

    /**
     * Offset just after the `[` of the exported configuration's own `plugins` array, or null when the
     * configuration is not a plain object literal (for example a function returning one).
     */
    public static function topLevelPluginsArrayEnd(string $source): ?int
    {
        $code = self::code($source);

        if (preg_match('/export\s+default\s+(?:defineConfig\s*\(\s*)?\{/', $code, $exportMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $objectStart = $exportMatch[0][1] + strlen($exportMatch[0][0]);

        if (preg_match_all('/\bplugins\s*:\s*\[/', $code, $matches, PREG_OFFSET_CAPTURE, $objectStart) === 0) {
            return null;
        }

        foreach ($matches[0] as [$match, $offset]) {
            $between = substr($code, $objectStart, $offset - $objectStart);

            // Depth zero relative to the exported object means a direct property of it.
            if (substr_count($between, '{') === substr_count($between, '}')) {
                return $offset + strlen($match);
            }
        }

        return null;
    }
}
