#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/release/ReleaseEngine.php';
require __DIR__ . '/release/ReleaseEligibility.php';

use Capell\Release\ProcessCommandRunner;
use Capell\Release\ReleaseEligibilityChecker;
use Capell\Release\ReleaseException;

$sha = $argv[1] ?? null;

if (! is_string($sha)) {
    fwrite(STDERR, "An exact Core source SHA is required.\n");
    exit(1);
}

try {
    $localEvidencePath = getenv('CAPELL_RELEASE_LOCAL_EVIDENCE');
    $evidence = new ReleaseEligibilityChecker(
        new ProcessCommandRunner,
        is_string($localEvidencePath) && $localEvidencePath !== '' ? $localEvidencePath : null,
    )->check($sha);
} catch (ReleaseException $releaseException) {
    fwrite(STDERR, $releaseException->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(
    STDOUT,
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);
