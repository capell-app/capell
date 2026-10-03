<?php

declare(strict_types=1);

use Capell\Core\Actions\RunNpmBuildAction;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

if (! function_exists('fakeProcessResult')) {
    function fakeProcessResult(bool $wasSuccessful, string $output = '', string $errorOutput = ''): ProcessResult
    {
        $result = Mockery::mock(ProcessResult::class);
        $result->shouldReceive('successful')->andReturn($wasSuccessful);
        $result->shouldReceive('output')->andReturn($output);
        $result->shouldReceive('errorOutput')->andReturn($errorOutput);

        return $result;
    }
}

if (! function_exists('expectNpmProcessCommand')) {
    function expectNpmProcessCommand(string $command, object $result): void
    {
        $pendingProcess = Mockery::mock();

        Process::shouldReceive('timeout')
            ->with(300)
            ->once()
            ->ordered()
            ->andReturn($pendingProcess);

        $pendingProcess->shouldReceive('run')
            ->with($command)
            ->once()
            ->ordered()
            ->andReturn($result);
    }
}

beforeEach(function (): void {
    Facade::clearResolvedInstance(Factory::class);
    Process::spy();
});

afterEach(function (): void {
    Mockery::close();
    Facade::clearResolvedInstance(Factory::class);
});

it('runs npm production build successfully', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    expectNpmProcessCommand('npm run build', fakeProcessResult(true));

    expect(fn (): mixed => RunNpmBuildAction::run(false))
        ->not()->toThrow(RuntimeException::class);
});

it('runs npm dev build successfully', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    expectNpmProcessCommand('npm run dev', fakeProcessResult(true));

    expect(fn (): mixed => RunNpmBuildAction::run(true))
        ->not()->toThrow(RuntimeException::class);
});

it('throws exception on build failure with error output', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    $errorMessage = 'npm ERR! code ENOENT';

    expectNpmProcessCommand('npm run build', fakeProcessResult(false, '', $errorMessage));

    RunNpmBuildAction::run(false);
})->throws(RuntimeException::class, 'npm ERR! code ENOENT');

it('throws exception on build failure with output when no error output', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    $output = 'Build failed due to syntax error';

    expectNpmProcessCommand('npm run build', fakeProcessResult(false, $output, ''));

    RunNpmBuildAction::run(false);
})->throws(RuntimeException::class, 'Build failed due to syntax error');

it('installs npm dependencies and retries when native binding is missing', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    $errorMessage = 'Cannot find native binding. npm has a bug related to optional dependencies.';

    expectNpmProcessCommand('npm run build', fakeProcessResult(false, '', $errorMessage));
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    expectNpmProcessCommand('npm run build', fakeProcessResult(true));

    expect(fn (): mixed => RunNpmBuildAction::run(false))
        ->not()->toThrow(RuntimeException::class);
});

it('installs npm dependencies and retries when rollup optional dependency is missing', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    $errorMessage = "Cannot find module '@rollup/rollup-linux-arm64-gnu'. npm has a bug related to optional dependencies.";

    expectNpmProcessCommand('npm run build', fakeProcessResult(false, '', $errorMessage));
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    expectNpmProcessCommand('npm run build', fakeProcessResult(true));

    expect(fn (): mixed => RunNpmBuildAction::run(false))
        ->not()->toThrow(RuntimeException::class);
});

it('specifies dev mode parameter correctly', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    expectNpmProcessCommand('npm run dev', fakeProcessResult(true));

    RunNpmBuildAction::run(isDev: true);

    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    expectNpmProcessCommand('npm run build', fakeProcessResult(true));

    RunNpmBuildAction::run(isDev: false);
});

it('installs newly prepared dependencies before building even with existing node modules', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(true));
    expectNpmProcessCommand('npm run build', fakeProcessResult(true));

    RunNpmBuildAction::run();
});

it('stops before building when dependency installation fails', function (): void {
    expectNpmProcessCommand('npm install', fakeProcessResult(false, '', 'dependency resolution failed'));
    expect(fn (): mixed => RunNpmBuildAction::run())->toThrow(RuntimeException::class, 'dependency resolution failed');
});

it('refuses npm before any process or second lockfile for a non-npm host', function (string $manager, ?string $lockfile): void {
    $path = base_path($lockfile ?? 'package.json');
    $before = is_file($path) ? file_get_contents($path) : null;
    $npmLock = base_path('package-lock.json');
    $npmBefore = is_file($npmLock) ? file_get_contents($npmLock) : null;
    file_put_contents($path, $lockfile === null ? json_encode(['packageManager' => $manager . '@1.0.0'], JSON_THROW_ON_ERROR) : 'owned lock fixture');
    Process::shouldReceive('timeout')->never();
    try {
        expect(function (): void {
            RunNpmBuildAction::run();
        })->toThrow(RuntimeException::class, 'npm-only');
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
