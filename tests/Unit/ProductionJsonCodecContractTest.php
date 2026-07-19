<?php

declare(strict_types=1);

it('routes production JSON operations through the core codec', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $allowedPaths = [
        'packages/core/src/Support/Json/JsonCodec.php',
    ];
    $violations = [];

    foreach (glob($repositoryRoot . '/packages/*/src', GLOB_ONLYDIR) ?: [] as $sourceDirectory) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($repositoryRoot . '/', '', $file->getPathname());

            if (in_array($relativePath, $allowedPaths, true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && $token[0] === T_STRING && in_array($token[1], ['json_decode', 'json_encode'], true)) {
                    $violations[] = $relativePath . ':' . $token[2] . ' uses ' . $token[1] . '()';
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
