#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/release/ReleaseEngine.php';

use Capell\Release\PlanValidator;
use Capell\Release\ReleaseEngine;
use Capell\Release\ReleaseException;

$root = dirname(__DIR__);
$command = $argv[1] ?? '';
try {
    if ($command === 'plan') {
        $baseline = null;
        $from = null;
        $bumps = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (str_starts_with($argument, '--baseline-version=')) {
                $baseline = substr($argument, 19);
            }
            if (str_starts_with($argument, '--from=')) {
                $from = substr($argument, 7);
            }
            if (str_starts_with($argument, '--bump=')) {
                $value = substr($argument, 7);
                if (! str_contains($value, '=')) {
                    throw new ReleaseException('Bumps use --bump=package=patch|minor|major.');
                }
                [$package, $type] = explode('=', $value, 2);
                $bumps[$package] = $type;
            }
        }
        $previous = $from === null ? null : json_decode((string) file_get_contents($from), true, 512, JSON_THROW_ON_ERROR);
        $version = $baseline ?? 'incremental';
        if (! is_string($version)) {
            throw new ReleaseException('plan requires --baseline-version or --from.');
        }
        echo json_encode((new ReleaseEngine($root))->plan($version, $previous, $bumps), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $path = $argv[2] ?? null;
    if ($path === null) {
        throw new ReleaseException("{$command} requires a plan path.");
    }
    $plan = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    (new PlanValidator)->validate($plan);
    if ($command === 'validate') {
        echo "Plan is valid.\n";
        exit(0);
    }
    $engine = new ReleaseEngine($root);
    if (in_array($command, ['publish', 'resume'], true)) {
        $engine->publish($plan, $path);
        echo "Publication state recorded.\n";
        exit(0);
    }
    if ($command === 'verify') {
        $engine->verify($plan, $path);
        echo "Remote release verified.\n";
        exit(0);
    }
    throw new ReleaseException("Unknown command {$command}.");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
