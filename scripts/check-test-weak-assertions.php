<?php

declare(strict_types=1);

/** @return list<int> */
function weakContainmentLines(string $source): array
{
    $tokens = token_get_all($source);
    $significant = array_values(array_filter($tokens, static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $lines = [];

    foreach ($significant as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        if (($significant[$index + 1][1] ?? null) !== 'not') {
            continue;
        }

        if (($significant[$index + 2][0] ?? null) !== T_OBJECT_OPERATOR) {
            continue;
        }

        if (($significant[$index + 3][1] ?? null) !== 'toContain') {
            continue;
        }

        if (($significant[$index + 4] ?? null) !== '(') {
            continue;
        }

        $depth = 0;
        $arguments = 0;
        $hasArgument = false;
        $unpacked = false;
        for ($i = $index + 5, $count = count($significant); $i < $count; $i++) {
            $part = $significant[$i];
            if ($depth === 0 && ($part === ',' || $part === ')')) {
                if ($hasArgument) {
                    $arguments++;
                    $hasArgument = false;
                }

                if ($part === ')') {
                    break;
                }

                continue;
            }

            if ($depth === 0) {
                $hasArgument = true;
                if (is_array($part) && $part[0] === T_ELLIPSIS) {
                    $unpacked = true;
                }
            }

            if (in_array($part, ['(', '[', '{'], true) || (is_array($part) && in_array($part[0], [T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($part, [')', ']', '}'], true)) {
                $depth--;
            }
        }

        if ($arguments > 1 || $unpacked) {
            $lines[] = $token[2];
        }
    }

    return $lines;
}

$arguments = array_slice($argv, 1);
if (count($arguments) > 1 || ($arguments !== [] && ! str_starts_with($arguments[0], '--root='))) {
    fwrite(STDERR, "Usage: php scripts/check-test-weak-assertions.php [--root=directory]\n");
    exit(1);
}

$roots = $arguments === []
    ? array_merge([dirname(__DIR__) . '/tests'], glob(dirname(__DIR__) . '/packages/*/tests', GLOB_ONLYDIR) ?: [])
    : [substr($arguments[0], strlen('--root='))];
$files = [];
foreach ($roots as $root) {
    if (! is_dir($root)) {
        fwrite(STDERR, sprintf('Cannot read test directory: %s%s', $root, PHP_EOL));
        exit(1);
    }

    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator($directory, static fn (SplFileInfo $entry): bool => ! $entry->isLink() && (! $entry->isDir() || ! in_array($entry->getFilename(), ['vendor', 'node_modules'], true)));
    foreach (new RecursiveIteratorIterator($filtered) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
$failed = false;
foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        fwrite(STDERR, sprintf('Cannot read test file: %s%s', $file, PHP_EOL));
        exit(1);
    }

    foreach (weakContainmentLines($source) as $line) {
        fwrite(STDERR, "{$file}:{$line}: Use ->toContainNone([A, B]) or separate ->not->toContain(...) assertions.\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
