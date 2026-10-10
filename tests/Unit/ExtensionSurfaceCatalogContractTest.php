<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps generated extension surface artifacts deterministic', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/build-extension-surface-catalog.php', '--check'], $root);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('catalogue is current');
});

it('requires direct contract IDs for every stable surface', function (): void {
    $catalog = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/docs/packages/extension-surface-catalog.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($catalog['schemaVersion'])->toBe(1)
        ->and($catalog['surfaces'])->not->toBeEmpty();

    foreach ($catalog['surfaces'] as $surface) {
        expect($surface['id'])->toMatch('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/');

        if ($surface['stability'] === 'stable') {
            expect($surface['contractTestId'])->not->toBeNull();
        }
    }
});

it('references the Core conformance suites from the harness catalogue entry', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = json_decode(
        (string) file_get_contents($root . '/docs/packages/extension-surface-catalog.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $surface = null;

    foreach ($catalog['surfaces'] as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === 'core.testing.extension-harness') {
            $surface = $candidate;

            break;
        }
    }

    throw_unless(is_array($surface), LogicException::class, 'The extension harness catalogue entry is missing.');

    $references = $surface['contractTestReferences'] ?? null;

    throw_unless(is_array($references), LogicException::class, 'The extension harness catalogue references are missing.');

    // The references are permalinks, so the commit and line anchor describe history, not the working tree.
    // Only the repository path and the named test section are contractual; neither depends on line positions.
    $expectedSections = [
        'tests/Feature/ExtensionConformanceTest.php' => "it('boots only the provider buckets allowed by the public runtime role'",
        'tests/Feature/ExtensionConformanceFailureTest.php' => "it('catches a loaded provider whose declared contribution emitted no receipt'",
    ];
    $referencedPaths = [];

    foreach ($references as $reference) {
        throw_unless(
            is_string($reference) && preg_match('#^https://github\.com/capell-app/capell/blob/[0-9a-f]{40}/(?<path>[^\#]+)(?:\#L\d+)?$#', $reference, $matches) === 1,
            LogicException::class,
            'Contract test reference is not a repository permalink.',
        );

        $referencedPaths[] = $matches['path'];
    }

    expect($referencedPaths)->toBe(array_keys($expectedSections));

    $documentation = (string) file_get_contents($root . '/docs/packages/extension-surface-catalog.md');

    foreach ($expectedSections as $path => $expectedSection) {
        expect($root . '/' . $path)->toBeFile()
            ->and((string) file_get_contents($root . '/' . $path))->toContain($expectedSection)
            ->and($documentation)->toContain(basename($path));
    }

    foreach ($references as $reference) {
        expect($documentation)->toContain($reference);
    }
});

it('links the human API references to the machine-owned catalogue', function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'docs/packages/extension-point-api-reference.md',
        'docs/packages/extension-surface-vocabulary.md',
    ] as $path) {
        expect((string) file_get_contents($root . '/' . $path))
            ->toContain('(extension-surface-catalog.md)');
    }
});
