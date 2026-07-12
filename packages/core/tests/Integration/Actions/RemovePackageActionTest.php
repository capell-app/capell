<?php

declare(strict_types=1);

use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\SymfonyProcessFactory;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $mockProcess = Mockery::mock(Process::class);

    $mockProcess
        ->shouldReceive('setEnv')
        ->with(Mockery::on(fn (array $environment): bool => ($environment['GIT_CONFIG_KEY_0'] ?? null) === 'safe.directory'
            && ($environment['GIT_CONFIG_VALUE_0'] ?? null) === '*'))
        ->andReturnSelf();

    $mockProcess
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();

    $mockProcess
        ->shouldReceive('run')
        ->andReturn(0);

    $mockProcess
        ->shouldReceive('getErrorOutput')
        ->andReturn('');

    $mockProcess
        ->shouldReceive('getOutput')
        ->andReturn('Package vendor/package removed');

    $mockProcess
        ->shouldReceive('isSuccessful')
        ->andReturnTrue();

    $mockFactory = Mockery::mock(ProcessFactoryInterface::class);

    $mockFactory
        ->shouldReceive('make')
        ->with(Mockery::on(fn (array|string $command): bool => $command === ['composer', 'remove', 'vendor/package', '--no-interaction', '--no-scripts']), Mockery::type('string'))
        ->andReturn($mockProcess);

    app()->instance(ProcessFactoryInterface::class, $mockFactory);
});

it('removes a package', function (): void {
    $filesystem = new class extends Filesystem
    {
        /** @var list<list<string>> */
        public array $deletedPaths = [];

        public function delete($paths): bool
        {
            $this->deletedPaths[] = array_values((array) $paths);

            return true;
        }
    };

    app()->instance(Filesystem::class, $filesystem);

    $result = RemovePackageAction::run('vendor/package');
    $deletedPaths = collect($filesystem->deletedPaths)->flatten()->all();

    expect($result)
        ->toBeArray()
        ->and($result['status'] ?? null)->toBe('removed')
        ->and($result['cache_cleared'] ?? null)->toBeTrue()
        ->and($deletedPaths)->toContain(
            base_path('bootstrap/cache/capell-package-manifests.php'),
            base_path('bootstrap/cache/capell-theme-chain.php'),
        )
        ->and($deletedPaths)->not->toContain(
            base_path('bootstrap/cache/packages.php'),
            base_path('bootstrap/cache/services.php'),
        );
});

it('builds a symfony process from the factory', function (): void {
    $factory = new SymfonyProcessFactory;

    $process = $factory->make(['composer', 'remove', 'vendor/package', '--no-interaction', '--no-scripts'], base_path());
    $commandLine = $process->getCommandLine();

    expect($process)
        ->toBeInstanceOf(Process::class)
        ->and($commandLine)->toContain('composer')
        ->and($commandLine)->toContain('remove')
        ->and($commandLine)->toContain('vendor/package')
        ->and($commandLine)->toContain('--no-scripts')
        ->and($process->getWorkingDirectory())->toBe(base_path());
});

it('promotes bundle members while preserving direct constraints', function (): void {
    $bundlePath = '/virtual/widget-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $filesystem = new BundleComposerFilesystem([
        $composerPath => json_encode(['require' => ['capell-app/widget-showcase' => '^4.1', 'capell-app/widget-slideshow' => '^9.0']], JSON_THROW_ON_ERROR),
        $lockPath => '{"lock":"before"}',
        $bundlePath . '/composer.json' => json_encode(['require' => ['capell-app/widget-slideshow' => '^4.1', 'capell-app/widget-youtube' => '^4.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('capell-app/widget-slideshow', version: '^4.1');
    CapellCore::registerPackage('capell-app/widget-youtube', version: '^4.1');
    CapellCore::registerPackage('capell-app/widget-showcase', path: $bundlePath, version: '^4.1');
    $bundle = CapellCore::getPackage('capell-app/widget-showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['capell-app/widget-slideshow', 'capell-app/widget-youtube'];

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->with(300)->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath): int {
        $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
        unset($composer['require']['capell-app/widget-showcase']);
        $filesystem->contents[$composerPath] = json_encode($composer, JSON_THROW_ON_ERROR);
        $filesystem->contents[$lockPath] = '{"lock":"after"}';

        return 0;
    });
    $process->shouldReceive('getErrorOutput')->andReturn('');
    $process->shouldReceive('getOutput')->andReturn('Bundle removed');
    $process->shouldReceive('isSuccessful')->andReturnTrue();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->with(['composer', 'remove', 'capell-app/widget-showcase', '--no-interaction', '--no-scripts'], Mockery::type('string'))->once()->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    RemovePackageAction::run('capell-app/widget-showcase');

    $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
    expect($composer['require'])->not->toHaveKey('capell-app/widget-showcase')
        ->and($composer['require']['capell-app/widget-slideshow'])->toBe('^9.0')
        ->and($composer['require']['capell-app/widget-youtube'])->toBe('^4.1')
        ->and($filesystem->contents[$lockPath])->toBe('{"lock":"after"}');
});

it('restores composer files when bundle deletion fails', function (): void {
    $bundlePath = '/virtual/failing-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/showcase' => '^4.1']], JSON_THROW_ON_ERROR);
    $originalLock = '{"lock":"original"}';
    $filesystem = new BundleComposerFilesystem([$composerPath => $originalComposer, $lockPath => $originalLock, $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/member' => '^4.1']], JSON_THROW_ON_ERROR)]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/member', version: '^4.1');
    CapellCore::registerPackage('vendor/showcase', path: $bundlePath, version: '^4.1');
    $bundle = CapellCore::getPackage('vendor/showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member'];

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturn(1);
    $process->shouldReceive('getErrorOutput')->andReturn('Resolution failed');
    $process->shouldReceive('getOutput')->andReturn('');
    $process->shouldReceive('isSuccessful')->andReturnFalse();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(fn () => RemovePackageAction::run('vendor/showcase'))->toThrow(
        RuntimeException::class,
        'Composer could not complete the package removal.',
    );
    expect($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock);
});

it('uses allow-listed diagnostics when composer removal fails', function (string $composerOutput, array $secrets): void {
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->with(300)->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturn(1);
    $process->shouldReceive('getErrorOutput')->andReturn($composerOutput);
    $process->shouldReceive('getOutput')->andReturn('');
    $process->shouldReceive('isSuccessful')->andReturnFalse();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->with(['composer', 'remove', 'vendor/unsafe-package', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    $caught = null;

    try {
        RemovePackageAction::run('vendor/unsafe-package');
    } catch (RuntimeException $runtimeException) {
        $caught = $runtimeException;
    }

    expect($caught?->getMessage())->toBe(
        'Composer could not complete the package removal. Composer output was withheld because it may contain credentials. '
        . 'Run the removal from the application root in a trusted terminal, resolve the reported Composer error, then retry.',
    )->and(mb_strlen((string) $caught?->getMessage()))->toBeLessThanOrEqual(300);

    foreach ($secrets as $secret) {
        expect($caught?->getMessage())->not->toContain($secret);
    }
})->with([
    'basic auth credentialed URL' => [
        'Download failed from https://composer-user:basic-auth-secret@example.test/archive.zip',
        ['basic-auth-secret'],
    ],
    'multiline composer auth' => [
        implode(PHP_EOL, [
            'COMPOSER_AUTH={',
            '  "github-oauth": {',
            '    "github.com": "multiline-composer-secret"',
            '  }',
            '}',
        ]),
        ['multiline-composer-secret'],
    ],
    'environment dump' => [
        implode(PHP_EOL, [
            'AWS_SECRET_ACCESS_KEY=aws-environment-secret',
            'INTERNAL_BUILD_CONTEXT=arbitrary-environment-secret',
            'PATH=/private/operator/environment/path',
        ]),
        ['aws-environment-secret', 'arbitrary-environment-secret', '/private/operator/environment/path'],
    ],
    'bearer password and provider token' => [
        implode(PHP_EOL, [
            'Authorization: Bearer bearer-secret-value',
            'password=database-secret',
            'GitHub rejected github_pat_naked-secret-value.',
        ]),
        ['bearer-secret-value', 'database-secret', 'naked-secret-value'],
    ],
]);

it('restores composer files when post-composer bundle finalization fails', function (): void {
    $bundlePath = '/virtual/finalization-failing-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/finalization-showcase' => '^4.1']], JSON_THROW_ON_ERROR);
    $originalLock = '{"lock":"original"}';
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/finalization-member' => '^4.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/finalization-member', version: '^4.1');
    CapellCore::registerPackage('vendor/finalization-showcase', path: $bundlePath, version: '^4.1');
    $bundle = CapellCore::getPackage('vendor/finalization-showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/finalization-member'];

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath): int {
        $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
        unset($composer['require']['vendor/finalization-showcase']);
        $filesystem->contents[$composerPath] = json_encode($composer, JSON_THROW_ON_ERROR);
        $filesystem->contents[$lockPath] = '{"lock":"changed"}';

        return 0;
    });
    $process->shouldReceive('getErrorOutput')->andReturn('');
    $process->shouldReceive('getOutput')->andReturn('Bundle removed');
    $process->shouldReceive('isSuccessful')->andReturnTrue();
    $recovery = Mockery::mock(Process::class);
    $recovery->shouldReceive('setEnv')->andReturnSelf();
    $recovery->shouldReceive('setTimeout')->andReturnSelf();
    $recovery->shouldReceive('run')->once()->andReturn(0);
    $recovery->shouldReceive('isSuccessful')->andReturnTrue();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->with(['composer', 'remove', 'vendor/finalization-showcase', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($process);
    $factory->shouldReceive('make')
        ->with(['composer', 'install', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($recovery);
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(fn () => RemovePackageAction::run(
        'vendor/finalization-showcase',
        static fn (): never => throw new RuntimeException('State finalization failed'),
    ))->toThrow(RuntimeException::class, 'State finalization failed');
    expect($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock);
});

it('updates already-direct bundle members and verifies the bundle leaves the lock file', function (): void {
    $bundlePath = '/virtual/transitive-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $filesystem = new BundleComposerFilesystem([
        $composerPath => json_encode(['require' => ['vendor/member' => '^4.1']], JSON_THROW_ON_ERROR),
        $lockPath => json_encode(['packages' => [['name' => 'vendor/showcase'], ['name' => 'vendor/member']]], JSON_THROW_ON_ERROR),
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/member' => '^4.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/member', version: '^4.1');
    CapellCore::registerPackage('vendor/showcase', path: $bundlePath, version: '^4.1');
    $bundle = CapellCore::getPackage('vendor/showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member'];

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function () use ($filesystem, $lockPath): int {
        $filesystem->contents[$lockPath] = json_encode(['packages' => [['name' => 'vendor/member']]], JSON_THROW_ON_ERROR);

        return 0;
    });
    $process->shouldReceive('getErrorOutput')->andReturn('');
    $process->shouldReceive('getOutput')->andReturn('Unused bundle removed');
    $process->shouldReceive('isSuccessful')->andReturnTrue();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->with(['composer', 'update', 'vendor/member', '--with-dependencies', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    RemovePackageAction::run('vendor/showcase');

    expect($filesystem->contents[$lockPath])->not->toContain('vendor/showcase');
});

it('restores composer files when a transitive bundle remains locked', function (): void {
    $bundlePath = '/virtual/retained-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/member' => '^4.1']], JSON_THROW_ON_ERROR);
    $originalLock = json_encode(['packages' => [['name' => 'vendor/showcase'], ['name' => 'vendor/member']]], JSON_THROW_ON_ERROR);
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/member' => '^4.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/member', version: '^4.1');
    CapellCore::registerPackage('vendor/showcase', path: $bundlePath, version: '^4.1');
    $bundle = CapellCore::getPackage('vendor/showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member'];

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturn(0);
    $process->shouldReceive('getErrorOutput')->andReturn('');
    $process->shouldReceive('getOutput')->andReturn('Nothing changed');
    $process->shouldReceive('isSuccessful')->andReturnTrue();
    $recovery = Mockery::mock(Process::class);
    $recovery->shouldReceive('setEnv')->andReturnSelf();
    $recovery->shouldReceive('setTimeout')->andReturnSelf();
    $recovery->shouldReceive('run')->once()->andReturn(0);
    $recovery->shouldReceive('isSuccessful')->andReturnTrue();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->with(['composer', 'update', 'vendor/member', '--with-dependencies', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($process);
    $factory->shouldReceive('make')
        ->with(['composer', 'install', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($recovery);
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(fn () => RemovePackageAction::run('vendor/showcase'))
        ->toThrow(RuntimeException::class, 'remains installed in composer.lock');
    expect($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock);
});

it('restores composer files and reports safe operator diagnostics when recovery fails', function (): void {
    $bundlePath = '/virtual/recovery-failing-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/recovery-showcase' => '^4.1']], JSON_THROW_ON_ERROR);
    $originalLock = json_encode(['packages' => [['name' => 'vendor/recovery-showcase'], ['name' => 'vendor/recovery-member']]], JSON_THROW_ON_ERROR);
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/recovery-member' => '^4.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/recovery-member', version: '^4.1');
    CapellCore::registerPackage('vendor/recovery-showcase', path: $bundlePath, version: '^4.1');
    $bundle = CapellCore::getPackage('vendor/recovery-showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/recovery-member'];

    $removal = Mockery::mock(Process::class);
    $removal->shouldReceive('setEnv')->andReturnSelf();
    $removal->shouldReceive('setTimeout')->andReturnSelf();
    $removal->shouldReceive('run')->once()->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath): int {
        $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
        unset($composer['require']['vendor/recovery-showcase']);
        $filesystem->contents[$composerPath] = json_encode($composer, JSON_THROW_ON_ERROR);
        $filesystem->contents[$lockPath] = json_encode(['packages' => [['name' => 'vendor/recovery-member']]], JSON_THROW_ON_ERROR);

        return 0;
    });
    $removal->shouldReceive('getErrorOutput')->andReturn('');
    $removal->shouldReceive('getOutput')->andReturn('Bundle removed');
    $removal->shouldReceive('isSuccessful')->andReturnTrue();

    $recoveryOutput = implode(PHP_EOL, [
        'Repository unavailable during automatic recovery.',
        'COMPOSER_AUTH={',
        '  "github-oauth": {',
        '    "github.com": "composer-auth-secret"',
        '  }',
        '}',
        'Authorization: Bearer bearer-secret-value',
        'password=database-secret',
        'AWS_SECRET_ACCESS_KEY=aws-environment-secret',
        'INTERNAL_BUILD_CONTEXT=arbitrary-environment-secret',
        'Download failed from https://composer-user:url-secret@example.test/archive.zip',
        'GitHub rejected github_pat_naked-secret-value.',
        str_repeat('FULL_ENVIRONMENT_VALUE=visible ', 300),
    ]);
    $recovery = Mockery::mock(Process::class);
    $recovery->shouldReceive('setEnv')->andReturnSelf();
    $recovery->shouldReceive('setTimeout')->andReturnSelf();
    $recovery->shouldReceive('run')->once()->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath): int {
        $filesystem->contents[$composerPath] = '{"corrupted":true}';
        $filesystem->contents[$lockPath] = '{"corrupted":true}';

        return 1;
    });
    $recovery->shouldReceive('isSuccessful')->andReturnFalse();
    $recovery->shouldReceive('getErrorOutput')->andReturn($recoveryOutput);
    $recovery->shouldReceive('getOutput')->andReturn('');

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->with(['composer', 'remove', 'vendor/recovery-showcase', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($removal);
    $factory->shouldReceive('make')
        ->with(['composer', 'install', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($recovery);
    app()->instance(ProcessFactoryInterface::class, $factory);

    $originalFailure = new RuntimeException('Package lifecycle finalization failed.');
    $caught = null;

    try {
        RemovePackageAction::run(
            'vendor/recovery-showcase',
            static fn (): never => throw $originalFailure,
        );
    } catch (RuntimeException $runtimeException) {
        $caught = $runtimeException;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught?->getMessage())->toBe(
            'Composer files were restored after package removal failed, but the installed package graph could not be recovered. '
            . 'Composer output was withheld because it may contain credentials. Installed dependencies may not match composer.lock. '
            . 'Run "composer install --no-interaction --no-scripts" from the application root in a trusted terminal.',
        )
        ->not->toContain('Repository unavailable during automatic recovery.')
        ->not->toContain('composer-auth-secret')
        ->not->toContain('bearer-secret-value')
        ->not->toContain('database-secret')
        ->not->toContain('aws-environment-secret')
        ->not->toContain('arbitrary-environment-secret')
        ->not->toContain('url-secret')
        ->not->toContain('naked-secret-value')
        ->and(mb_strlen((string) $caught?->getMessage()))->toBeLessThanOrEqual(400)
        ->and($caught?->getPrevious())->toBe($originalFailure)
        ->and($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock);
});

it('wraps recovery process creation setup and timeout failures safely', function (string $failurePoint): void {
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/throwing-package' => '^4.1']], JSON_THROW_ON_ERROR);
    $originalLock = json_encode(['packages' => [['name' => 'vendor/throwing-package']]], JSON_THROW_ON_ERROR);
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
    ]);
    app()->instance(Filesystem::class, $filesystem);

    $removal = Mockery::mock(Process::class);
    $removal->shouldReceive('setEnv')->andReturnSelf();
    $removal->shouldReceive('setTimeout')->with(300)->andReturnSelf();
    $removal->shouldReceive('run')->once()->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath): int {
        $filesystem->contents[$composerPath] = '{"require":[]}';
        $filesystem->contents[$lockPath] = '{"packages":[]}';

        return 0;
    });
    $removal->shouldReceive('getErrorOutput')->andReturn('');
    $removal->shouldReceive('getOutput')->andReturn('Package removed');
    $removal->shouldReceive('isSuccessful')->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->with(['composer', 'remove', 'vendor/throwing-package', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($removal);

    if ($failurePoint === 'creation') {
        $factory->shouldReceive('make')
            ->with(['composer', 'install', '--no-interaction', '--no-scripts'], Mockery::type('string'))
            ->once()
            ->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath): never {
                $filesystem->contents[$composerPath] = '{"corrupted":"creation"}';
                $filesystem->contents[$lockPath] = '{"corrupted":"creation"}';

                throw new RuntimeException('Recovery factory exposed recovery-factory-secret.');
            });
    } else {
        $recovery = Mockery::mock(Process::class);

        if ($failurePoint === 'setup') {
            $recovery->shouldReceive('setEnv')->once()->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath): never {
                $filesystem->contents[$composerPath] = '{"corrupted":"setup"}';
                $filesystem->contents[$lockPath] = '{"corrupted":"setup"}';

                throw new RuntimeException('Recovery setup exposed recovery-setup-secret.');
            });
        } else {
            $timedProcess = new Process(['composer', 'install', 'timeout-secret']);
            $timedProcess->setTimeout(300);
            $timeout = new ProcessTimedOutException($timedProcess, ProcessTimedOutException::TYPE_GENERAL);

            $recovery->shouldReceive('setEnv')->once()->andReturnSelf();
            $recovery->shouldReceive('setTimeout')->with(300)->once()->andReturnSelf();
            $recovery->shouldReceive('run')->once()->andReturnUsing(function () use ($filesystem, $composerPath, $lockPath, $timeout): never {
                $filesystem->contents[$composerPath] = '{"corrupted":"timeout"}';
                $filesystem->contents[$lockPath] = '{"corrupted":"timeout"}';

                throw $timeout;
            });
        }

        $factory->shouldReceive('make')
            ->with(['composer', 'install', '--no-interaction', '--no-scripts'], Mockery::type('string'))
            ->once()
            ->andReturn($recovery);
    }

    app()->instance(ProcessFactoryInterface::class, $factory);
    $originalFailure = new RuntimeException('Package lifecycle finalization failed.');
    $caught = null;

    try {
        RemovePackageAction::run(
            'vendor/throwing-package',
            static fn (): never => throw $originalFailure,
        );
    } catch (RuntimeException $runtimeException) {
        $caught = $runtimeException;
    }

    expect($caught?->getMessage())->toBe(
        'Composer files were restored after package removal failed, but the installed package graph could not be recovered. '
        . 'Composer output was withheld because it may contain credentials. Installed dependencies may not match composer.lock. '
        . 'Run "composer install --no-interaction --no-scripts" from the application root in a trusted terminal.',
    )
        ->not->toContain('recovery-factory-secret')
        ->not->toContain('recovery-setup-secret')
        ->not->toContain('timeout-secret')
        ->and($caught?->getPrevious())->toBe($originalFailure)
        ->and($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock);
})->with(['creation', 'setup', 'timeout']);

final class BundleComposerFilesystem extends Filesystem
{
    /** @param array<string, string> $contents */
    public function __construct(public array $contents) {}

    #[Override]
    public function exists($path): bool
    {
        return array_key_exists((string) $path, $this->contents);
    }

    #[Override]
    public function get($path, $lock = false): string
    {
        return $this->contents[(string) $path];
    }

    #[Override]
    public function replace($path, $content, $mode = null): void
    {
        $this->contents[(string) $path] = (string) $content;
    }

    #[Override]
    public function delete($paths): bool
    {
        foreach ((array) $paths as $path) {
            unset($this->contents[(string) $path]);
        }

        return true;
    }
}
