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

    if (! is_array($surface)) {
        throw new LogicException('The extension harness catalogue entry is missing.');
    }

    $references = $surface['contractTestReferences'] ?? null;

    if (! is_array($references)) {
        throw new LogicException('The extension harness catalogue references are missing.');
    }

    $expectedSections = [
        'https://github.com/capell-app/capell/blob/b052f23730ac6dcd3bf6a7470a4e95c12f06b443/tests/Feature/ExtensionConformanceTest.php#L24' => "it('boots only the provider buckets allowed by the public runtime role'",
        'https://github.com/capell-app/capell/blob/b052f23730ac6dcd3bf6a7470a4e95c12f06b443/tests/Feature/ExtensionConformanceFailureTest.php#L26' => "it('catches a loaded provider whose declared contribution emitted no receipt'",
    ];

    expect($references)->toBe(array_keys($expectedSections));

    foreach ($expectedSections as $reference => $expectedSection) {
        if (! is_string($reference) || ! is_string($expectedSection)) {
            throw new LogicException('Contract test references must be strings.');
        }

        $path = (string) parse_url($reference, PHP_URL_PATH);
        $line = (int) ltrim((string) parse_url($reference, PHP_URL_FRAGMENT), 'L');
        $source = file($root . '/' . ltrim(str_replace('/capell-app/capell/blob/b052f23730ac6dcd3bf6a7470a4e95c12f06b443/', '', $path), '/'), FILE_IGNORE_NEW_LINES);

        if ($source === false || $line < 1 || ! isset($source[$line - 1])) {
            throw new LogicException('Contract test reference target is missing.');
        }

        expect(str_starts_with($reference, 'https://github.com/capell-app/capell/blob/b052f23730ac6dcd3bf6a7470a4e95c12f06b443/'))
            ->toBeTrue()
            ->and($source[$line - 1])->toContain($expectedSection);
    }

    expect((string) file_get_contents($root . '/docs/packages/extension-surface-catalog.md'))
        ->toContain('[ExtensionConformanceTest.php](https://github.com/capell-app/capell/blob/b052f23730ac6dcd3bf6a7470a4e95c12f06b443/tests/Feature/ExtensionConformanceTest.php#L24)')
        ->toContain('[ExtensionConformanceFailureTest.php](https://github.com/capell-app/capell/blob/b052f23730ac6dcd3bf6a7470a4e95c12f06b443/tests/Feature/ExtensionConformanceFailureTest.php#L26)');
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
