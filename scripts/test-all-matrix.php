<?php

declare(strict_types=1);

require_once __DIR__ . '/test-all/TestAllMatrix.php';
require_once __DIR__ . '/test-all/TestAllScope.php';

$arguments = $_SERVER['argv'] ?? [];
$group = $arguments[1] ?? null;
$cellArgument = array_values(array_filter(
    $arguments,
    static fn (mixed $argument): bool => is_string($argument) && str_starts_with($argument, '--cell='),
))[0] ?? null;
$cellId = is_string($cellArgument) ? substr($cellArgument, strlen('--cell=')) : null;
$option = static fn (string $name): ?string => array_values(array_filter(
    array_map(
        static fn (mixed $argument): ?string => is_string($argument) && str_starts_with($argument, $name . '=') ? substr($argument, strlen($name) + 1) : null,
        $arguments,
    ),
))[0] ?? null;
$changedFrom = $option('--changed-from');
$changedTo = $option('--changed-to');

$matrix = match ($group) {
    'sentinel' => TestAllMatrix::sentinel(),
    'behaviour' => TestAllMatrix::behaviour(),
    'unit' => TestAllMatrix::unit(),
    'portability' => TestAllMatrix::portability(),
    'target' => [...TestAllMatrix::behaviour(), ...TestAllMatrix::unit(), ...TestAllMatrix::portability()],
    'all' => TestAllMatrix::all(),
    default => throw new InvalidArgumentException(
        'Usage: php scripts/test-all-matrix.php sentinel|behaviour|unit|portability|target|all [--github-output]',
    ),
};

if ($changedFrom !== null && $changedTo !== null && in_array($group, ['behaviour', 'unit', 'all'], true)) {
    // Per-package cells narrow to the packages the change can affect; every other cell stays.
    $requiredBy = TestAllScope::requiredBy(dirname(__DIR__));
    $matrix = TestAllScope::filter(
        $matrix,
        TestAllScope::packages(TestAllScope::changedFiles(dirname(__DIR__), $changedFrom, $changedTo), $requiredBy),
        array_keys($requiredBy),
    );
}

if (is_string($cellId)) {
    $cell = TestAllMatrix::find($cellId);
    $matrix = array_values(array_filter(
        $matrix,
        static fn (array $candidate): bool => $candidate['id'] === $cell['id'],
    ));

    if ($group === 'target' && $matrix === []) {
        throw new InvalidArgumentException(
            sprintf('Test All cell [%s] cannot be dispatched as a targeted hosted repair cell.', $cellId),
        );
    }
}

$json = json_encode(['include' => $matrix], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

if (in_array('--github-output', $arguments, true)) {
    echo 'matrix=' . $json, PHP_EOL;

    return;
}

echo $json, PHP_EOL;
