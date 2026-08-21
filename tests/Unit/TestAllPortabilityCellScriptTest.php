<?php

declare(strict_types=1);

it('runs a portability cell against a generated disposable database service', function (): void {
    $root = dirname(__DIR__, 2);
    $temporaryDirectory = sys_get_temp_dir() . '/capell-portability-runtime-' . bin2hex(random_bytes(6));
    $dockerCapture = $temporaryDirectory . '/docker.jsonl';
    $composerCapture = $temporaryDirectory . '/composer.json';
    $outputDirectory = $temporaryDirectory . '/output';
    mkdir($temporaryDirectory, 0700, true);

    $composerPath = $temporaryDirectory . '/composer';
    file_put_contents($composerPath, <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

file_put_contents((string) getenv('COMPOSER_CAPTURE_PATH'), json_encode([
    'argv' => $argv,
    'database_connection' => getenv('DB_CONNECTION'),
    'database_family' => getenv('CAPELL_TEST_DATABASE_FAMILY'),
    'database_version' => getenv('CAPELL_TEST_DATABASE_VERSION'),
    'database_host' => getenv('DB_HOST'),
    'database_port' => getenv('DB_PORT'),
    'database_name' => getenv('DB_DATABASE'),
    'database_username' => getenv('DB_USERNAME'),
], JSON_THROW_ON_ERROR));
PHP);
    chmod($composerPath, 0700);

    $dockerPath = $temporaryDirectory . '/docker';
    file_put_contents($dockerPath, implode(PHP_EOL, [
        '#!/usr/bin/env php',
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        'file_put_contents(',
        "    (string) getenv('DOCKER_CAPTURE_PATH'),",
        '    json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . PHP_EOL,',
        '    FILE_APPEND,',
        ');',
        '',
        'match ($argv[1] ?? null) {',
        '    \'run\' => print "disposable-container-id\\n",',
        '    \'inspect\' => print "healthy\\n",',
        '    \'port\' => print "127.0.0.1:49152\\n",',
        '    \'rm\' => print "removed\\n",',
        "    default => throw new RuntimeException('Unexpected Docker command.'),",
        '};',
        '',
    ]));
    chmod($dockerPath, 0700);

    $environment = [
        'COMPOSER_CAPTURE_PATH' => $composerCapture,
        'DOCKER_CAPTURE_PATH' => $dockerCapture,
        'PATH' => $temporaryDirectory . PATH_SEPARATOR . getenv('PATH'),
    ];
    $command = implode(' ', array_map(
        static fn (string $key, string $value): string => $key . '=' . escapeshellarg($value),
        array_keys($environment),
        $environment,
    )) . sprintf(
        ' php %s --cell=l13-portability-postgresql-16 --output-dir=%s 2>&1',
        escapeshellarg($root . '/scripts/run-test-all-portability-cell.php'),
        escapeshellarg($outputDirectory),
    );

    try {
        exec($command, $output, $exitCode);

        expect($exitCode)->toBe(0, implode(PHP_EOL, $output));

        $composer = json_decode((string) file_get_contents($composerCapture), true, flags: JSON_THROW_ON_ERROR);
        $dockerCommands = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode(PHP_EOL, trim((string) file_get_contents($dockerCapture))))),
        );

        expect($composer)->toMatchArray([
            'database_connection' => 'pgsql',
            'database_family' => 'postgresql',
            'database_version' => '16',
            'database_host' => '127.0.0.1',
            'database_port' => '49152',
            'database_username' => 'postgres',
        ])
            ->and($composer['database_name'])->toMatch('/\Acapell_test_[0-9a-f]{8}\z/')
            ->and(array_column($dockerCommands, 0))->toBe(['run', 'inspect', 'port', 'rm'])
            ->and($dockerCommands[0])->toContain('postgres:16')
            ->and($dockerCommands[3])->toContain('--force');
    } finally {
        @unlink($outputDirectory . '/pest-output-portability-postgresql-16.txt');
        @rmdir($outputDirectory);
        @unlink($dockerCapture);
        @unlink($composerCapture);
        @unlink($dockerPath);
        @unlink($composerPath);
        @rmdir($temporaryDirectory);
    }
});
