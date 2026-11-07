<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;

it('keeps strict types declarations out of first-party Blade files', function (): void {
    $root = dirname(__DIR__, 2);

    expect(coreBladeStrictTypeOffenders($root, coreBladeStrictTypePaths($root)))->toBe([]);
});

it('detects strict types declarations throughout Blade fixtures', function (string $contents): void {
    $root = sys_get_temp_dir() . '/capell-blade-guard-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($root . '/tests/.hidden/nested');

    try {
        file_put_contents($root . '/tests/.hidden/nested/example.blade.php', $contents);

        expect(coreBladeStrictTypeOffenders($root, ['tests/.hidden/nested/example.blade.php']))
            ->toBe(['tests/.hidden/nested/example.blade.php']);
    } finally {
        $filesystem->remove($root);
    }
})->with([
    'opening declaration' => '<?php declare(strict_types=1);',
    'later block' => '<div>content</div><?php declare(strict_types=1);',
    'whitespace' => "<?php declare (\n strict_types = 0 );",
    'comment between tokens' => '<?php declare /* comment */ ( strict_types=1 );',
    'textual mention' => '{{-- declare(strict_types=1) --}}',
]);

it('rejects an empty or unreadable Blade inventory', function (): void {
    expect(fn (): array => coreBladeStrictTypeOffenders(__DIR__, []))->toThrow(RuntimeException::class)
        ->and(fn (): array => coreBladeStrictTypeOffenders(__DIR__, ['missing.blade.php']))->toThrow(RuntimeException::class);
});

it('includes hidden, ignored and nested Blade fixtures while excluding generated dependencies', function (): void {
    $root = sys_get_temp_dir() . '/capell-blade-guard-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($root . '/tests/.hidden/nested');

    try {
        $filesystem->mkdir($root . '/vendor');
        $filesystem->mkdir($root . '/packages/demo/vendor');
        $filesystem->mkdir($root . '/resources/views/vendor');
        file_put_contents($root . '/.gitignore', '*.blade.php');
        file_put_contents($root . '/tests/.hidden/nested/example.blade.php', '<p>safe</p>');
        file_put_contents($root . '/tests/.hidden/nested/example.php', '<?php declare(strict_types=1);');
        file_put_contents($root . '/vendor/generated.blade.php', '<?php declare(strict_types=1);');
        file_put_contents($root . '/packages/demo/vendor/generated.blade.php', '<?php declare(strict_types=1);');
        file_put_contents($root . '/resources/views/vendor/override.blade.php', '<p>safe</p>');

        $paths = coreBladeStrictTypePaths($root);
        expect($paths)->toBe(['resources/views/vendor/override.blade.php', 'tests/.hidden/nested/example.blade.php'])
            ->and(coreBladeStrictTypeOffenders($root, $paths))->toBe([]);
    } finally {
        $filesystem->remove($root);
    }
});

it('refuses to follow Blade files or source directories through symlinks', function (): void {
    $root = sys_get_temp_dir() . '/capell-blade-guard-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($root);

    try {
        $filesystem->mkdir($root . '/source');
        $filesystem->mkdir($root . '/outside');
        file_put_contents($root . '/outside/target.blade.php', '<p>safe</p>');
        $filesystem->symlink($root . '/outside/target.blade.php', $root . '/source/linked.blade.php');
        expect(fn (): array => coreBladeStrictTypePaths($root . '/source'))->toThrow(RuntimeException::class);
        $filesystem->remove($root . '/source/linked.blade.php');
        $filesystem->symlink($root . '/outside', $root . '/source/linked-directory');
        expect(fn (): array => coreBladeStrictTypePaths($root . '/source'))->toThrow(RuntimeException::class);
        expect(fn (): array => coreBladeStrictTypeOffenders($root . '/source', ['linked-directory/target.blade.php']))->toThrow(RuntimeException::class);
        $filesystem->remove($root . '/source/linked-directory');
        $filesystem->symlink($root . '/missing', $root . '/source/dangling');
        expect(fn (): array => coreBladeStrictTypePaths($root . '/source'))->toThrow(RuntimeException::class);
    } finally {
        $filesystem->remove($root);
    }
});

/** @return list<string> */
function coreBladeStrictTypePaths(string $root): array
{
    $root = rtrim($root, '/');
    $excluded = ['.git', '.claude', '.codex', '.playwright', 'vendor', 'node_modules', 'storage', 'var', 'output', 'bootstrap/cache', 'public/build', 'public/storage', 'public/vendor'];
    $iterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($iterator, static function (SplFileInfo $file) use ($root, $excluded): bool {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (in_array($relative, $excluded, true) || $file->getFilename() === 'node_modules' || preg_match('~^(?:packages/[^/]+|workbench)/vendor$~', $relative) === 1) {
            return false;
        }

        if ($file->isLink()) {
            throw_if(! file_exists($file->getPathname()) || $file->isDir() || str_ends_with($file->getFilename(), '.blade.php'), RuntimeException::class, 'Cannot safely inspect Blade source symlink: ' . $relative);

            return false;
        }

        return true;
    });

    $paths = [];
    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if (str_ends_with((string) $file->getFilename(), '.blade.php')) {
            $paths[] = substr((string) $file->getPathname(), strlen($root) + 1);
        }
    }

    sort($paths);

    return $paths;
}

/** @param list<string> $paths @return list<string> */
function coreBladeStrictTypeOffenders(string $root, array $paths): array
{
    throw_if($paths === [], RuntimeException::class, 'No first-party Blade files were found.');

    $offenders = [];
    foreach ($paths as $path) {
        $file = $root . '/' . $path;
        $ancestor = $root;
        foreach (explode('/', $path) as $component) {
            $ancestor .= '/' . $component;
            throw_if(is_link($ancestor), RuntimeException::class, 'Cannot safely read Blade file through symlink: ' . $path);
        }

        throw_if(! is_file($file) || ! is_readable($file), RuntimeException::class, 'Cannot safely read Blade file: ' . $path);

        $contents = file_get_contents($file);
        throw_if($contents === false, RuntimeException::class, 'Cannot read Blade file: ' . $path);

        // Match the text anywhere, including comments, and allow PHP comments between tokens.
        $gap = '(?:\s|/\*.*?\*/|//[^\r\n]*(?:\r\n|\r|\n|$)|#[^\r\n]*(?:\r\n|\r|\n|$))*';
        $matched = preg_match('~\bdeclare' . $gap . '\(' . $gap . 'strict_types\b~is', $contents);
        throw_if($matched === false, RuntimeException::class, 'Could not inspect Blade file: ' . $path);

        if ($matched === 1) {
            $offenders[] = $path;
        }
    }

    sort($offenders);

    return $offenders;
}
