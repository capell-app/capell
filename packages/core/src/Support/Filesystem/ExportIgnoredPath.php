<?php

declare(strict_types=1);

namespace Capell\Core\Support\Filesystem;

final class ExportIgnoredPath
{
    public static function matches(string $packageDirectory, string $relativePath): bool
    {
        $packageDirectory = realpath($packageDirectory);

        if ($packageDirectory === false) {
            return false;
        }

        $relativePath = self::normaliseRelativePath($relativePath);

        if ($relativePath === null) {
            return false;
        }

        $ignored = false;
        $pathSegments = explode('/', $relativePath);

        foreach (range(0, count($pathSegments) - 1) as $depth) {
            $attributeDirectory = $packageDirectory;

            if ($depth > 0) {
                $attributeDirectory .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($pathSegments, 0, $depth));
            }

            $attributesPath = $attributeDirectory . DIRECTORY_SEPARATOR . '.gitattributes';

            if (! is_file($attributesPath)) {
                continue;
            }

            $pathFromAttributeDirectory = implode('/', array_slice($pathSegments, $depth));

            foreach (file($attributesPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $rule = self::attributeRule($line);
                if ($rule === null) {
                    continue;
                }

                if (! self::patternMatches($rule['pattern'], $pathFromAttributeDirectory)) {
                    continue;
                }

                $ignored = $rule['ignored'];
            }
        }

        return $ignored;
    }

    /** @return array{pattern: string, ignored: bool}|null */
    private static function attributeRule(string $line): ?array
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $tokens = preg_split('/\s+/', $line);

        if (! is_array($tokens) || count($tokens) < 2) {
            return null;
        }

        $pattern = array_shift($tokens);

        if (! is_string($pattern) || $pattern === '') {
            return null;
        }

        $ignored = null;

        foreach ($tokens as $attribute) {
            if ($attribute === 'export-ignore' || str_starts_with($attribute, 'export-ignore=')) {
                $ignored = true;
            }

            if ($attribute === '-export-ignore') {
                $ignored = false;
            }
        }

        return $ignored === null ? null : ['pattern' => $pattern, 'ignored' => $ignored];
    }

    private static function patternMatches(string $pattern, string $path): bool
    {
        $anchored = str_starts_with($pattern, '/');
        $pattern = trim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        $expression = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === '*') {
                if (($pattern[$index + 1] ?? null) === '*') {
                    $expression .= '.*';
                    $index++;
                } else {
                    $expression .= '[^/]*';
                }

                continue;
            }

            if ($character === '?') {
                $expression .= '[^/]';

                continue;
            }

            $expression .= preg_quote($character, '/');
        }

        $expression = $anchored || str_contains($pattern, '/')
            ? '^' . $expression . '(?:/.*)?$'
            : '(?:^|/)' . $expression . '(?:/.*)?$';

        return preg_match('~' . $expression . '~', $path) === 1;
    }

    private static function normaliseRelativePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }

        $segments = explode('/', $path);

        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        return $path;
    }
}
