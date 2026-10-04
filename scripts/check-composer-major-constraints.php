<?php

declare(strict_types=1);

require_once __DIR__ . '/ComposerMajorConstraints.php';

$root = getenv('CAPELL_COMPOSER_CONSTRAINT_ROOT') ?: dirname(__DIR__);

try {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';

    if (! is_file($autoload)) {
        throw new RuntimeException('Composer constraint checks require vendor/autoload.php and composer/semver; install repository dependencies first.');
    }

    require_once $autoload;

    $failures = ComposerMajorConstraints::failures($root);
} catch (JsonException|RuntimeException|UnexpectedValueException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);

    exit(2);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);

    exit(1);
}

fwrite(STDOUT, 'Composer constraints admit locked versions in published manifests and command overrides; audited holds match their locked majors.' . PHP_EOL);
