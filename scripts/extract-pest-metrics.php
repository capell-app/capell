#!/usr/bin/env php
<?php

declare(strict_types=1);

[$script, $outputPath, $githubOutputFlag] = $argv + [null, null, null];

if (! is_string($outputPath) || ! is_file($outputPath)) {
    fwrite(STDERR, "A Pest output file is required.\n");
    exit(1);
}

$output = file_get_contents($outputPath);

if ($output === false) {
    fwrite(STDERR, sprintf("Unable to read %s.\n", $outputPath));
    exit(1);
}

$output = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $output) ?? $output;
$patterns = [
    '/Tests:\s*([0-9,]+)\D+Assertions:\s*([0-9,]+)/i',
    '/\b([0-9,]+)\s+tests?,\s*([0-9,]+)\s+assertions?\b/i',
    '/Tests:\s*([0-9,]+).*?\(([0-9,]+)\s+assertions?\)/is',
];
$tests = null;
$assertions = null;

foreach ($patterns as $pattern) {
    if (preg_match_all($pattern, $output, $matches, PREG_SET_ORDER) === 0) {
        continue;
    }

    $lastMatch = $matches[array_key_last($matches)];
    $tests = (int) str_replace(',', '', $lastMatch[1]);
    $assertions = (int) str_replace(',', '', $lastMatch[2]);

    break;
}

if ($tests === null || $assertions === null) {
    fwrite(STDERR, "Unable to parse test/assertion counts from Pest output.\n");
    exit(1);
}

if ($githubOutputFlag === '--github-output') {
    $githubOutput = getenv('GITHUB_OUTPUT');

    if (! is_string($githubOutput) || $githubOutput === '') {
        fwrite(STDERR, "GITHUB_OUTPUT is not available.\n");
        exit(1);
    }

    file_put_contents(
        $githubOutput,
        sprintf("tests=%d\nassertions=%d\n", $tests, $assertions),
        FILE_APPEND,
    );
}

fwrite(STDOUT, sprintf("Parsed engineering metrics: tests=%d, assertions=%d\n", $tests, $assertions));
