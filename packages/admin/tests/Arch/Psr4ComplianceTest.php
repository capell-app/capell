<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Arch;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

it('all named classes in the test suite live in their own PSR-4 file', function (): void {
    $testsPath = realpath(__DIR__ . '/..');

    throw_if(
        in_array($testsPath, ['', '0', false], true) || ! is_dir($testsPath),
        RuntimeException::class,
        'Tests path does not exist: ' . $testsPath,
    );

    $violations = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsPath, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $expectedBasename = $file->getBasename('.php');
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] !== T_CLASS) {
                continue;
            }

            // T_CLASS is also emitted for `::class` constant references and
            // `new [readonly] class {...}` anonymous classes — neither declares
            // a named class. Walk back over whitespace, comments, and class
            // modifiers (readonly/final/abstract) to find the governing token:
            // `::` means a ::class reference, `new` means an anonymous class.
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev])
                && in_array($tokens[$prev][0], [
                    T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                    T_READONLY, T_FINAL, T_ABSTRACT,
                ], true)) {
                $prev--;
            }

            if ($prev >= 0 && is_array($tokens[$prev])
                && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (! is_array($tokens[$j])) {
                    break;
                }

                if ($tokens[$j][0] === T_STRING) {
                    $className = $tokens[$j][1];

                    if ($className !== $expectedBasename) {
                        $relative = str_replace($testsPath . '/', '', $file->getPathname());
                        $violations[] = sprintf('Class %s in %s — move to %s.php in a Fixtures/ subdirectory', $className, $relative, $className);
                    }

                    break;
                }
            }
        }
    }

    expect($violations, implode(PHP_EOL, $violations))->toBeEmpty();
});
