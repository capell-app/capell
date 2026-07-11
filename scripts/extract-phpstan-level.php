<?php

declare(strict_types=1);

$configurationPath = getenv('CAPELL_PHPSTAN_COMMON_NEON') ?: dirname(__DIR__) . '/phpstan/common.neon';
$writeGithubOutput = in_array('--github-output', $argv, true);
$valueOnly = in_array('--value-only', $argv, true);

if (! is_file($configurationPath)) {
    fwrite(STDERR, "PHPStan common config not found at {$configurationPath}.\n");

    exit(1);
}

$contents = file_get_contents($configurationPath);

if ($contents === false) {
    fwrite(STDERR, "Unable to read PHPStan common config at {$configurationPath}.\n");

    exit(1);
}

if (preg_match('/^\s*level:\s*([0-9]+)\s*$/m', $contents, $matches) !== 1) {
    fwrite(STDERR, "Unable to extract PHPStan level from {$configurationPath}.\n");

    exit(1);
}

$level = (int) $matches[1];

if ($writeGithubOutput) {
    $githubOutput = getenv('GITHUB_OUTPUT');

    if (! is_string($githubOutput) || $githubOutput === '') {
        fwrite(STDERR, "GITHUB_OUTPUT is not available.\n");

        exit(1);
    }

    file_put_contents($githubOutput, "phpstan_level={$level}\n", FILE_APPEND);
}

fwrite(STDOUT, $valueOnly ? ((string) $level . "\n") : "PHPStan level: {$level}\n");
