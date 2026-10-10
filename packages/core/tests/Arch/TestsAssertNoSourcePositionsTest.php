<?php

declare(strict_types=1);

// Tests must survive unrelated edits. A permalink pinned to a commit and a line anchor, or a source file indexed by
// line number, fails whenever code above the target moves, even though nothing is broken.
it('does not pin tests to source line positions or commit-pinned line anchors', function (): void {
    $root = dirname(__DIR__, 4);
    $banned = [
        '/blob\/[0-9a-f]{40}\/[^\s\'"]*#L\d+/' => 'commit-pinned permalink with a line anchor',
        '/\$source\[\$line\s*-\s*1\]/' => 'source file indexed by line number',
    ];
    $violations = [];

    foreach (['tests', 'packages'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (! str_contains((string) $path, '/tests/') && ! str_starts_with((string) $path, $root . '/tests/')) {
                continue;
            }

            if (str_contains((string) $path, '/vendor/')) {
                continue;
            }

            if (str_contains((string) $path, '/node_modules/')) {
                continue;
            }

            if ($file->getFilename() === 'TestsAssertNoSourcePositionsTest.php') {
                continue;
            }

            $contents = (string) file_get_contents($path);

            foreach ($banned as $pattern => $description) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = substr((string) $path, strlen($root) + 1) . ': ' . $description;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
