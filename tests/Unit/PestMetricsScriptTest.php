<?php

declare(strict_types=1);

it('extracts the final Pest test and assertion counts for matrix aggregation', function (): void {
    $outputPath = tempnam(sys_get_temp_dir(), 'pest-output-');
    file_put_contents($outputPath, implode(PHP_EOL, [
        'Tests: 12 passed (34 assertions)',
        'Tests: 2,761 passed (10,643 assertions)',
    ]));

    $command = sprintf(
        '%s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2) . '/scripts/extract-pest-metrics.php'),
        escapeshellarg($outputPath),
    );
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode(PHP_EOL, $output))->toContain('tests=2761, assertions=10643');

    @unlink($outputPath);
});
