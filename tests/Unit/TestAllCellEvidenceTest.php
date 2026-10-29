<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;

it('requires fresh completed test evidence before accepting a successful command', function (string $report, bool $stale, int $expected): void {
    $fixture = new CommandFixture;
    foreach (['scripts/run-test-all-cell.php', 'scripts/test-all/ProcessRunner.php', 'scripts/test-all/TestAllMatrix.php'] as $script) {
        $fixture->files->copy($script);
    }

    $fixture->fake('composer', '$report = getenv("FIXTURE_REPORT"); if ($report !== "") { file_put_contents(getenv("PEST_JUNIT_LOG"), $report); }');
    if ($stale) {
        $fixture->files->write('output/junit-sentinel-unit.xml', '<testsuites><testsuite><testcase name="stale"/></testsuite></testsuites>');
    }

    try {
        $process = $fixture->run([PHP_BINARY, 'scripts/run-test-all-cell.php', '--cell=sentinel-unit', '--output-dir=output'], ['FIXTURE_REPORT' => $report]);
        expect($process->getExitCode())->toBe($expected, $process->getOutput() . $process->getErrorOutput());
        if ($expected !== 0) {
            expect($process->getErrorOutput())->toContain('Test All evidence');
        }
    } finally {
        $fixture->close();
    }
})->with([
    'missing report' => ['', false, 2],
    'malformed report' => ['<testsuites>', false, 2],
    'empty report' => ['<testsuites/>', false, 2],
    'failed test despite successful command' => ['<testsuites><testsuite><testcase><failure/></testcase></testsuite></testsuites>', false, 2],
    'stale report' => ['', true, 2],
    'complete report' => ['<testsuites><testsuite><testcase name="completed"/></testsuite></testsuites>', false, 0],
]);
