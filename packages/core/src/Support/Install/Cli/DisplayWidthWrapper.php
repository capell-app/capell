<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

/**
 * Wraps text to a number of terminal columns. Unlike wordwrap(), it measures display width rather
 * than bytes, so accented and wide characters wrap where they appear, and it splits a single word
 * that is wider than the column so a long path or URL cannot push a table past the screen edge.
 */
final class DisplayWidthWrapper
{
    /** @return list<string> */
    public static function wrap(string $text, int $width): array
    {
        $width = max(1, $width);
        $lines = [];
        $line = '';

        foreach (preg_split('/ +/', $text) ?: [] as $word) {
            foreach (self::split($word, $width) as $piece) {
                $candidate = $line === '' ? $piece : $line . ' ' . $piece;

                if ($line !== '' && mb_strwidth($candidate) > $width) {
                    $lines[] = $line;
                    $line = $piece;

                    continue;
                }

                $line = $candidate;
            }
        }

        $lines[] = $line;

        return $lines;
    }

    /** @return list<string> */
    private static function split(string $word, int $width): array
    {
        if (mb_strwidth($word) <= $width) {
            return [$word];
        }

        $pieces = [];
        $piece = '';

        foreach (mb_str_split($word) as $character) {
            if ($piece !== '' && mb_strwidth($piece . $character) > $width) {
                $pieces[] = $piece;
                $piece = '';
            }

            $piece .= $character;
        }

        $pieces[] = $piece;

        return $pieces;
    }
}
