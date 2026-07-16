<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('validates heading, duplicate heading, and HTML id link targets', function (): void {
    $root = documentationLinksFixture([
        'README.md' => <<<'MARKDOWN'
# Top

[First repeated heading](docs/target.md#repeated-heading)
[Second repeated heading](docs/target.md#repeated-heading-1)
[Named HTML section](docs/target.md#named-section)
[This document](#top)
MARKDOWN,
        'docs/target.md' => <<<'MARKDOWN'
# Repeated Heading

# Repeated Heading

<details id="named-section">
    <summary>Named section</summary>
</details>

```
# This fenced heading is not a link target
```
MARKDOWN,
    ]);

    try {
        $process = documentationLinksProcess($root);

        $process->mustRun();

        expect($process->getOutput())->toContain('0 broken');
    } finally {
        documentationLinksDeleteDirectory($root);
    }
});

it('reports a missing heading link target', function (): void {
    $root = documentationLinksFixture([
        'README.md' => '[Missing heading](docs/target.md#missing-heading)',
        'docs/target.md' => '# Present heading',
    ]);

    try {
        $process = documentationLinksProcess($root);

        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('README.md:1 -> docs/target.md#missing-heading');
    } finally {
        documentationLinksDeleteDirectory($root);
    }
});

/**
 * @param  array<string, string>  $files
 */
function documentationLinksFixture(array $files): string
{
    $root = sys_get_temp_dir() . '/capell-doc-links-' . bin2hex(random_bytes(8));

    foreach ($files as $path => $contents) {
        $file = $root . '/' . $path;
        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($file, $contents);
    }

    return $root;
}

function documentationLinksProcess(string $root): Process
{
    return new Process(
        [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-docs-links.php'],
        $root,
        ['CAPELL_DOCS_LINKS_ROOT' => $root],
    );
}

function documentationLinksDeleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}
