<?php

declare(strict_types=1);

require_once __DIR__ . '/test-all/TestAllMatrix.php';

$arguments = $_SERVER['argv'] ?? [];
$group = $arguments[1] ?? null;
$cellArgument = array_values(array_filter(
    $arguments,
    static fn (mixed $argument): bool => is_string($argument) && str_starts_with($argument, '--cell='),
))[0] ?? null;
$cellId = is_string($cellArgument) ? substr($cellArgument, strlen('--cell=')) : null;

$matrix = match ($group) {
    'sentinel' => TestAllMatrix::sentinel(),
    'behaviour' => TestAllMatrix::behaviour(),
    'unit' => TestAllMatrix::unit(),
    'all' => TestAllMatrix::all(),
    default => throw new InvalidArgumentException(
        'Usage: php scripts/test-all-matrix.php sentinel|behaviour|unit|all [--github-output]',
    ),
};

if (is_string($cellId)) {
    $cell = TestAllMatrix::find($cellId);
    $matrix = array_values(array_filter(
        $matrix,
        static fn (array $candidate): bool => $candidate['id'] === $cell['id'],
    ));
}

$json = json_encode(['include' => $matrix], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

if (in_array('--github-output', $arguments, true)) {
    echo 'matrix=' . $json, PHP_EOL;

    return;
}

echo $json, PHP_EOL;
