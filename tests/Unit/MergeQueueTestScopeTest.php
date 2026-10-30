<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('skips the merge queue matrix only for changes Test All does not cover', function (): void {
    $root = dirname(__DIR__, 2);
    $testAll = Yaml::parseFile($root . '/.github/workflows/test-full.yml');
    $scopePaths = file($root . '/.github/ci/test-all-paths.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($scopePaths)->toContain(...$testAll['on']['pull_request']['paths'])
        ->toContain('.github/workflows/merge-queue-test-all.yml', '.github/ci/test-all-paths.txt');
});

it('runs the merge queue matrix whenever its scope cannot be decided', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/merge-queue-test-all.yml');

    expect($workflow['jobs']['full-matrix']['needs'])->toBe('scope')
        ->and($workflow['jobs']['full-matrix']['if'])->toContain("needs.scope.result != 'success'")
        ->and($workflow['jobs']['gate']['steps'][1]['env']['MATRIX_REQUIRED'])->toContain("needs.scope.result != 'success'");
});
