<?php

declare(strict_types=1);

use Capell\Core\Tests\Support\NestedTestProcess;

it('isolates nested test processes from worker and shared service settings', function (): void {
    $values = [
        'PARATEST' => '1', 'TEST_TOKEN' => '9', 'UNIQUE_TEST_TOKEN' => 'worker-9',
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'shared', 'DB_HOST' => 'shared-host',
        'REDIS_HOST' => 'shared-host', 'CACHE_STORE' => 'redis', 'QUEUE_CONNECTION' => 'redis',
        'SESSION_DRIVER' => 'redis',
    ];
    $original = [];
    $originalEnv = $_ENV;
    $originalServer = $_SERVER;
    foreach ($values as $key => $value) {
        $original[$key] = getenv($key);
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $code = 'echo json_encode(array_map(fn ($key) => getenv($key), ' . var_export(array_keys($values), true) . '));';
        $process = NestedTestProcess::make([PHP_BINARY, '-r', $code]);
        $process->mustRun();
        $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        expect($actual)->toBe([false, false, false, 'sqlite', ':memory:', false, false, false, false, false]);
    } finally {
        $_ENV = $originalEnv;
        $_SERVER = $originalServer;
        foreach ($original as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});
