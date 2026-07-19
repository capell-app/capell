<?php

declare(strict_types=1);

it('keeps the shipped capell skill free of monorepo-only paths', function (): void {
    $skillRoot = dirname(__DIR__, 2) . '/resources/boost/skills/capell';
    $violations = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($skillRoot, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            $violations[] = str_replace($skillRoot . '/', '', $file->getPathname()) . ' (unreadable)';

            continue;
        }

        if (preg_match('~packages/(core|admin|frontend|installer|marketplace)/(src|database|resources)|capell-4~', $contents) === 1) {
            $violations[] = str_replace($skillRoot . '/', '', $file->getPathname());
        }
    }

    expect($violations)->toBeEmpty();
});

it('keeps every relative docs llms link resolvable', function (): void {
    $docsRoot = dirname(__DIR__, 4) . '/docs';
    $indexPath = $docsRoot . '/llms.txt';

    expect($indexPath)->toBeFile();

    $contents = file_get_contents($indexPath);
    expect($contents)->toBeString()
        ->toStartWith("# Capell CMS\n\n> ")
        ->toContain("\n## Docs\n");

    preg_match_all('/\[[^\]]+]\(([^)]+)\)/', $contents, $matches);

    $missing = [];

    foreach ($matches[1] as $link) {
        if (! is_string($link) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|#)~i', $link) === 1) {
            continue;
        }

        $path = rawurldecode((string) preg_replace('/[#?].*$/', '', $link));

        if (! file_exists($docsRoot . '/' . $path)) {
            $missing[] = $link;
        }
    }

    expect($matches[1])->not->toBeEmpty()
        ->and($missing)->toBeEmpty();
});
