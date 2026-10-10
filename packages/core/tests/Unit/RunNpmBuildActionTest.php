<?php

declare(strict_types=1);

use Capell\Core\Actions\RunNpmBuildAction;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('installs prepared dependencies and builds the selected asset mode', function (bool $development, string $command): void {
    Process::fake(['npm install' => Process::result(), $command => Process::result()]);

    Process::preventStrayProcesses();
    RunNpmBuildAction::run(isDev: $development);

    // The external build command and its time budget are the builder's API.
    Process::assertRan('npm install');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === $command && $process->timeout === 300);
})->with(['production' => [false, 'npm run build'], 'development' => [true, 'npm run dev']]);

it('retains build diagnostics from stderr or stdout', function (string $output, string $error, string $message): void {
    Process::fake([
        'npm install' => Process::result(),
        'npm run build' => Process::result(output: $output, errorOutput: $error, exitCode: 1),
    ]);

    Process::preventStrayProcesses();
    expect(fn (): mixed => RunNpmBuildAction::run())->toThrow(RuntimeException::class, $message);
})->with([
    'stderr' => ['', 'npm ERR! code ENOENT', 'npm ERR! code ENOENT'],
    'stdout fallback' => ['Build failed due to syntax error', '', 'Build failed due to syntax error'],
]);

it('recovers a missing optional native dependency and finishes the build', function (string $diagnostic): void {
    Process::fake([
        'npm install' => Process::result(),
        'npm run build' => Process::sequence([
            Process::result(errorOutput: $diagnostic, exitCode: 1),
            Process::result(output: 'Build completed'),
        ]),
    ]);

    Process::preventStrayProcesses();
    RunNpmBuildAction::run();

    Process::assertRan('npm run build');
})->with([
    'native binding' => 'Cannot find native binding. npm has a bug related to optional dependencies.',
    'rollup' => "Cannot find module '@rollup/rollup-linux-arm64-gnu'. npm has a bug related to optional dependencies.",
]);

it('stops before building when dependencies cannot be installed', function (): void {
    Process::fake(['npm install' => Process::result(errorOutput: 'dependency resolution failed', exitCode: 1)]);

    Process::preventStrayProcesses();
    expect(fn (): mixed => RunNpmBuildAction::run())->toThrow(RuntimeException::class, 'dependency resolution failed');
    Process::assertDidntRun('npm run build');
});

it('refuses npm before any process or second lockfile for a non-npm host', function (string $manager, ?string $lockfile): void {
    $path = base_path($lockfile ?? 'package.json');
    $before = is_file($path) ? file_get_contents($path) : null;
    $npmLock = base_path('package-lock.json');
    $npmBefore = is_file($npmLock) ? file_get_contents($npmLock) : null;
    file_put_contents($path, $lockfile === null ? json_encode(['packageManager' => $manager . '@1.0.0'], JSON_THROW_ON_ERROR) : 'owned lock fixture');
    Process::fake();
    Process::preventStrayProcesses();
    try {
        expect(function (): void {
            Process::preventStrayProcesses();
            RunNpmBuildAction::run();
        })->toThrow(RuntimeException::class, 'npm-only');
        Process::assertNothingRan();
        expect(is_file($npmLock) ? file_get_contents($npmLock) : null)->toBe($npmBefore);
    } finally {
        if ($before === null) {
            unlink($path);
        } else {
            file_put_contents($path, $before);
        }
    }
})->with([
    'declared pnpm' => ['pnpm', null],
    'declared yarn' => ['yarn', null],
    'declared bun' => ['bun', null],
    'pnpm lock' => ['pnpm', 'pnpm-lock.yaml'],
    'yarn lock' => ['yarn', 'yarn.lock'],
    'bun text lock' => ['bun', 'bun.lock'],
    'bun binary lock' => ['bun', 'bun.lockb'],
]);
