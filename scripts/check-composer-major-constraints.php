<?php

declare(strict_types=1);

require_once __DIR__ . '/ComposerMajorConstraints.php';

$root = getenv('CAPELL_COMPOSER_CONSTRAINT_ROOT') ?: dirname(__DIR__);

try {
    $failures = ComposerMajorConstraints::failures($root);
} catch (JsonException|RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);

    exit(2);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);

    exit(1);
}

fwrite(STDOUT, 'Composer constraints admit locked stable majors in published manifests and command overrides.' . PHP_EOL);
