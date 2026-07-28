<?php

declare(strict_types=1);

require_once __DIR__ . '/test-all/ProcessRunner.php';

$parsedOptions = getopt('', ['laravel:', 'testbench:']);
$options = is_array($parsedOptions) ? $parsedOptions : [];
$laravel = $options['laravel'] ?? null;
$testbench = $options['testbench'] ?? null;
$supported = [
    '12.*' => '10.*',
    '13.*' => '11.*',
];

if (! is_string($laravel) || ! is_string($testbench) || ($supported[$laravel] ?? null) !== $testbench) {
    fwrite(STDERR, 'Usage: php scripts/prepare-test-all-dependencies.php --laravel=12.* --testbench=10.*' . PHP_EOL);

    exit(2);
}

$commands = [
    ['php', 'scripts/check-composer-local-paths.php'],
    ['composer', 'run', 'check:composer-lock'],
    ['composer', 'remove', '--no-interaction', '--no-progress', '--ansi', '--dev', '--no-update', 'laravel/pao'],
    ['composer', 'require', '--no-interaction', '--no-progress', '--ansi', '--no-update', 'laravel/framework:' . $laravel],
    ['composer', 'require', '--no-interaction', '--no-progress', '--ansi', '--dev', '--no-update', 'orchestra/testbench:' . $testbench],
    [
        'composer',
        'update',
        '--no-interaction',
        '--no-progress',
        '--ansi',
        '--optimize-autoloader',
        '--with-all-dependencies',
        '-W',
    ],
];

foreach ($commands as $command) {
    $exitCode = ProcessRunner::run($command, dirname(__DIR__));

    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
