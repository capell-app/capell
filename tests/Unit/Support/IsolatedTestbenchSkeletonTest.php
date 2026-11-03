<?php

declare(strict_types=1);

use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('prepares and reuses a process skeleton and removes it on shutdown', function (): void {
    $root = sys_get_temp_dir() . '/capell-skeleton-test-' . bin2hex(random_bytes(8));
    $checkout = dirname(__DIR__, 3);
    $code = <<<'CODE'
        require $argv[1] . '/vendor/autoload.php';
        $first = \Capell\Tests\Support\IsolatedTestbenchSkeleton::basePath();
        $second = \Capell\Tests\Support\IsolatedTestbenchSkeleton::basePath();
        echo json_encode([$first, $second, is_dir($first . '/bootstrap'), is_dir($first . '/vendor'), is_dir($first . '/storage/app/public'), json_decode(file_get_contents($first . '/.capell-skeleton'), true)]);
        CODE;
    $process = new Process([PHP_BINARY, '-r', $code, $checkout], $checkout, ['CAPELL_TESTBENCH_SKELETON_ROOT' => $root]);
    try {
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        expect($result[0])->toBe($result[1])
            ->and($result[2])->toBeTrue()->and($result[3])->toBeTrue()->and($result[4])->toBeTrue()
            ->and($result[5]['checkout'])->toBe(realpath($checkout))
            ->and($result[5]['pid'])->toBeInt()
            ->and(file_exists($result[0]))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('boots from a base path without symlink components so release-root guards accept it', function (): void {
    $root = sys_get_temp_dir() . '/capell-skeleton-test-' . bin2hex(random_bytes(8)) . '/nested';
    $checkout = dirname(__DIR__, 3);
    $code = <<<'CODE'
        require $argv[1] . '/vendor/autoload.php';
        $base = \Capell\Tests\Support\IsolatedTestbenchSkeleton::basePath();
        echo json_encode([$base, realpath($base)]);
        CODE;
    $process = new Process([PHP_BINARY, '-r', $code, $checkout], $checkout, ['CAPELL_TESTBENCH_SKELETON_ROOT' => $root]);
    try {
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        [$base, $resolved] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $temp = realpath(sys_get_temp_dir());
        expect($resolved)->toBeString()
            ->and($base)->toBe($resolved)
            ->and(is_string($temp) && str_starts_with((string) $base, rtrim($temp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))->toBeTrue();
    } finally {
        (new Filesystem)->deleteDirectory(dirname($root));
    }
});

it('copies vendor and public storage when links cannot be created', function (): void {
    $root = sys_get_temp_dir() . '/capell-skeleton-copy-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($root . '/source/public');
    $filesystem->ensureDirectoryExists($root . '/vendor/composer');
    file_put_contents($root . '/source/app.php', 'skeleton');
    file_put_contents($root . '/vendor/composer/autoload_real.php', 'loader');
    try {
        $prepare = new ReflectionMethod(IsolatedTestbenchSkeleton::class, 'prepare');
        $prepare->invoke(null, $root . '/source', $root . '/target', fn (): bool => false, $root . '/vendor');
        expect(file_get_contents($root . '/target/app.php'))->toBe('skeleton')
            ->and(file_get_contents($root . '/target/vendor/composer/autoload_real.php'))->toBe('loader')
            ->and(is_dir($root . '/target/public/storage'))->toBeTrue()
            ->and(is_file($root . '/target/.capell-skeleton'))->toBeTrue();
    } finally {
        $filesystem->deleteDirectory($root);
    }
});

it('uses the run-unique Paratest token and falls back to the worker token', function (): void {
    $originalUniqueToken = getenv('UNIQUE_TEST_TOKEN');
    $originalTestToken = getenv('TEST_TOKEN');
    $token = new ReflectionMethod(IsolatedTestbenchSkeleton::class, 'token');
    try {
        putenv('TEST_TOKEN=11');
        putenv('UNIQUE_TEST_TOKEN=11_run-unique');
        expect($token->invoke(null))->toBe('11_run-unique');
        putenv('UNIQUE_TEST_TOKEN');
        expect($token->invoke(null))->toBe('11');
    } finally {
        putenv($originalUniqueToken === false ? 'UNIQUE_TEST_TOKEN' : 'UNIQUE_TEST_TOKEN=' . $originalUniqueToken);
        putenv($originalTestToken === false ? 'TEST_TOKEN' : 'TEST_TOKEN=' . $originalTestToken);
    }
});
