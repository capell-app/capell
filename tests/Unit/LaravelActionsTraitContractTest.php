<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('uses explicit object and fake traits instead of the Laravel Actions umbrella trait', function (): void {
    $violations = [];
    $actionFiles = 0;

    foreach ((new Finder)->files()->in([dirname(__DIR__, 2) . '/packages', __DIR__ . '/..'])->name('*.php') as $file) {
        $contents = $file->getContents();

        if (str_contains($contents, 'Lorisleiva\\Actions\\Concerns\\AsAction') || preg_match('/\\buse\\s+AsAction\\s*;/', $contents) === 1) {
            $violations[] = "{$file->getRelativePathname()}: uses AsAction";

            continue;
        }

        if (preg_match('/\\buse\\s+AsObject\\s*;/', $contents) !== 1) {
            continue;
        }

        $actionFiles++;

        if (! str_contains($contents, 'Lorisleiva\\Actions\\Concerns\\AsFake') || preg_match('/\\buse\\s+AsFake\\s*;/', $contents) !== 1) {
            $violations[] = "{$file->getRelativePathname()}: AsObject actions must include AsFake";
        }
    }

    expect($actionFiles)->toBeGreaterThan(0)
        ->and($violations)->toBe([], 'Laravel Actions must use AsObject and AsFake explicitly, plus only the granular adapter traits they require.');
});

it('invokes Laravel Actions through run rather than handle', function (): void {
    $files = (new Finder)->files()->in([dirname(__DIR__, 2) . '/packages', __DIR__ . '/..'])->name('*.php');
    $actionNames = [];
    $contentsByPath = [];

    foreach ($files as $file) {
        $contents = $file->getContents();
        $contentsByPath[$file->getRelativePathname()] = $contents;

        if (preg_match('/final class ([A-Za-z0-9_]+Action)\b.*?\buse AsObject;/s', $contents, $matches) === 1) {
            $actionNames[$matches[1]] = true;
        }
    }

    $violations = [];
    foreach ($contentsByPath as $path => $contents) {
        preg_match_all('/(?:new\s+(?:[A-Za-z_\\\\]+\\\\)?([A-Za-z_][A-Za-z0-9_]*Action)\s*\([^;]*?\)|(?:app|resolve)\(\s*(?:[A-Za-z_\\\\]+\\\\)?([A-Za-z_][A-Za-z0-9_]*Action)::class\s*\)|([A-Za-z_][A-Za-z0-9_]*Action)::make\s*\([^)]*\))\s*->handle\s*\(/s', $contents, $directMatches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        foreach ($directMatches as $directMatch) {
            $actionName = $directMatch[1] ?: $directMatch[2] ?: $directMatch[3];

            if (isset($actionNames[$actionName])) {
                $violations[] = "{$path}: directly invokes {$actionName}::handle()";
            }
        }

        preg_match_all('/\b(?:[A-Za-z_\\\\]+\\\\)?([A-Za-z_][A-Za-z0-9_]*Action)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $contents, $propertyMatches, PREG_SET_ORDER);

        foreach ($propertyMatches as $propertyMatch) {
            if (isset($actionNames[$propertyMatch[1]]) && str_contains($contents, "\$this->{$propertyMatch[2]}->handle(")) {
                $violations[] = "{$path}: directly invokes {$propertyMatch[1]}::handle()";
            }
        }
    }

    expect($violations)->toBe([], 'Laravel Actions must be invoked with Action::run(...), including in tests.');
});
