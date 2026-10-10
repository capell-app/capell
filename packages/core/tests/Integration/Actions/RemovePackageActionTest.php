<?php

declare(strict_types=1);

use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Process\SymfonyProcessFactory;
use Capell\Core\Tests\Support\BundleComposerFilesystem;
use Capell\Tests\Support\Fakes\FakeProcess;
use Capell\Tests\Support\Fakes\FakeProcessFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->processes = FakeProcessFactory::bind()->byDefault(output: 'Package vendor/package removed');
});

/** @return list<string> */
function composerRemovalArgv(string $package): array
{
    return [...capellComposerArgv(), 'remove', $package, '--no-interaction', '--no-scripts', '--no-audit', '--no-progress'];
}

/** @return list<string> */
function composerRecoveryArgv(): array
{
    return [...capellComposerArgv(), 'install', '--no-interaction', '--no-scripts'];
}

/** @return list<string> */
function composerMemberUpdateArgv(string $member): array
{
    return [...capellComposerArgv(), 'update', $member, '--with-dependencies', '--no-interaction', '--no-scripts', '--no-audit', '--no-progress'];
}

function expectComposerRemovalProcess(FakeProcess $process, string $package, int $timeout = 600): void
{
    expect($process->command)->toBe(composerRemovalArgv($package))
        ->and($process->getWorkingDirectory())->toBeString()
        ->and($process->getTimeout())->toEqual($timeout)
        ->and($process->getEnv())->toMatchArray(['GIT_CONFIG_KEY_0' => 'safe.directory', 'GIT_CONFIG_VALUE_0' => '*'])
        ->and($process->hasRun())->toBeTrue();
}

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

    expect($this->processes->processes)->toHaveCount(1);
    expectComposerRemovalProcess($this->processes->processes[0], 'vendor/package');

    expect($result)
        ->toBeArray()
        ->and($result['status'])->toBe('removed')
        ->and($result['cache_cleared'])->toBeTrue()
        ->and($deletedPaths)->toContain(
            base_path('bootstrap/cache/capell-package-manifests.php'),
            base_path('bootstrap/cache/capell-theme-chain.php'),
        )
        ->and($deletedPaths)->not->toContain(
            base_path('bootstrap/cache/packages.php'),
            base_path('bootstrap/cache/services.php'),
        );
});

it('keeps the current Laravel provider manifests until Composer has removed the package', function (): void {
    $filesystem = new class extends Filesystem
    {
        /** @var list<list<string>> */
        public array $deletedPaths = [];

        /** @var array<string, string> */
        public array $replacedContents = [];

        public function delete($paths): bool
        {
            $this->deletedPaths[] = array_values((array) $paths);

            return true;
        }

        public function replace($path, $content, $mode = null): void
        {
            $this->replacedContents[(string) $path] = (string) $content;
        }

        public function getRequire($path, array $data = []): mixed
        {
            $contents = $this->replacedContents[(string) $path] ?? null;

            if (is_string($contents)) {
                return eval(substr($contents, 5));
            }

            return parent::getRequire($path, $data);
        }
    };

    app()->instance(Filesystem::class, $filesystem);
    app()->instance(PackageManifest::class, new class($filesystem, base_path(), base_path('bootstrap/cache/packages.php')) extends PackageManifest
    {
        public function build(): void
        {
            $this->files->replace($this->manifestPath, '<?php return ' . var_export([
                'vendor/package' => ['providers' => ['Vendor\\PackageServiceProvider']],
                'vendor/other' => ['providers' => ['Vendor\\OtherServiceProvider']],
            ], return: true) . ';');
        }
    });
    app()->detectEnvironment(fn (): string => 'production');

    $this->processes->push(output: 'Package vendor/package removed', onRun: function () use ($filesystem): void {
        $deletedBeforeComposerCompleted = collect($filesystem->deletedPaths)->flatten()->all();
        $preparedManifest = $filesystem->replacedContents[base_path('bootstrap/cache/packages.php')] ?? '';

        expect($preparedManifest)
            ->not->toContain('vendor/package')
            ->toContain('vendor/other')
            ->and($deletedBeforeComposerCompleted)->not->toContain(base_path('bootstrap/cache/packages.php'))
            ->and($deletedBeforeComposerCompleted)->toContain(base_path('bootstrap/cache/services.php'));
    });

    try {
        RemovePackageAction::run('vendor/package');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect($this->processes->processes)->toHaveCount(1)
        ->and($this->processes->processes[0]->hasRun())->toBeTrue()
        ->and($this->processes->processes[0]->getTimeout())->toEqual(600)
        ->and(collect($filesystem->deletedPaths)->flatten()->all())->toContain(
            base_path('bootstrap/cache/packages.php'),
            base_path('bootstrap/cache/services.php'),
        );
});

it('removes a package from the command line while server side tooling is disabled', function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', false);

    // CAPELL_SERVER_SIDE_TOOLING gates unattended, web-triggered Composer runs.
    // An operator running capell:install or the uninstall command is attended,
    // so requiring the flag there would force every operator to set it.
    $result = RemovePackageAction::run('vendor/package');

    expect($result['status'])->toBe('removed');
});

it('builds a symfony process from the factory', function (): void {
    $factory = new SymfonyProcessFactory;

    $process = $factory->make([...capellComposerArgv(), 'remove', 'vendor/package', '--no-interaction', '--no-scripts', '--no-audit', '--no-progress'], base_path());
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
        $composerPath => json_encode(['require' => ['capell-app/widget-showcase' => '^1.1', 'capell-app/widget-slideshow' => '^9.0']], JSON_THROW_ON_ERROR),
        $lockPath => '{"lock":"before"}',
        $bundlePath . '/composer.json' => json_encode(['require' => ['capell-app/widget-slideshow' => '^1.1', 'capell-app/widget-youtube' => '^1.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('capell-app/widget-slideshow', version: '^1.1');
    CapellCore::registerPackage('capell-app/widget-youtube', version: '^1.1');
    CapellCore::registerPackage('capell-app/widget-showcase', path: $bundlePath, version: '^1.1');
    $bundle = CapellCore::getPackage('capell-app/widget-showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['capell-app/widget-slideshow', 'capell-app/widget-youtube'];

    $this->processes->push(output: 'Bundle removed', onRun: function () use ($filesystem, $composerPath, $lockPath): void {
        $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
        unset($composer['require']['capell-app/widget-showcase']);
        $filesystem->contents[$composerPath] = json_encode($composer, JSON_THROW_ON_ERROR);
        $filesystem->contents[$lockPath] = '{"lock":"after"}';
    });

    RemovePackageAction::run('capell-app/widget-showcase');

    expect($this->processes->processes)->toHaveCount(1);
    expectComposerRemovalProcess($this->processes->processes[0], 'capell-app/widget-showcase');

    $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
    expect($composer['require'])->not->toHaveKey('capell-app/widget-showcase')
        ->and($composer['require']['capell-app/widget-slideshow'])->toBe('^9.0')
        ->and($composer['require']['capell-app/widget-youtube'])->toBe('^1.1')
        ->and($filesystem->contents[$lockPath])->toBe('{"lock":"after"}');
});

it('restores composer files when bundle deletion fails', function (): void {
    $bundlePath = '/virtual/failing-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/showcase' => '^1.1']], JSON_THROW_ON_ERROR);
    $originalLock = '{"lock":"original"}';
    $filesystem = new BundleComposerFilesystem([$composerPath => $originalComposer, $lockPath => $originalLock, $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/member' => '^1.1']], JSON_THROW_ON_ERROR)]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/member', version: '^1.1');
    CapellCore::registerPackage('vendor/showcase', path: $bundlePath, version: '^1.1');
    $bundle = CapellCore::getPackage('vendor/showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member'];

    $this->processes->byDefault(exitCode: 1, errorOutput: 'Resolution failed');

    expect(fn () => RemovePackageAction::run('vendor/showcase'))->toThrow(
        RuntimeException::class,
        'Composer could not complete the package removal.',
    );
    expect($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock);
});

it('uses allow-listed diagnostics when composer removal fails', function (string $composerOutput, array $secrets): void {
    $this->processes->push(exitCode: 1, errorOutput: $composerOutput);

    $caught = null;

    try {
        RemovePackageAction::run('vendor/unsafe-package');
    } catch (RuntimeException $runtimeException) {
        $caught = $runtimeException;
    }

    expect($caught?->getMessage())->toBe(
        'Composer could not complete the package removal. Composer output was withheld because it may contain credentials. '
        . 'Run the removal from the application root in a trusted terminal, resolve the reported Composer error, then retry.',
    )->and(mb_strlen((string) $caught?->getMessage()))->toBeLessThanOrEqual(300)
        ->and($this->processes->processes)->toHaveCount(1);
    expectComposerRemovalProcess($this->processes->processes[0], 'vendor/unsafe-package');

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
    $originalComposer = json_encode(['require' => ['vendor/finalization-showcase' => '^1.1']], JSON_THROW_ON_ERROR);
    $originalLock = '{"lock":"original"}';
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/finalization-member' => '^1.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/finalization-member', version: '^1.1');
    CapellCore::registerPackage('vendor/finalization-showcase', path: $bundlePath, version: '^1.1');
    $bundle = CapellCore::getPackage('vendor/finalization-showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/finalization-member'];

    $this->processes
        ->push(output: 'Bundle removed', onRun: function () use ($filesystem, $composerPath, $lockPath): void {
            $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
            unset($composer['require']['vendor/finalization-showcase']);
            $filesystem->contents[$composerPath] = json_encode($composer, JSON_THROW_ON_ERROR);
            $filesystem->contents[$lockPath] = '{"lock":"changed"}';
        })
        ->push();

    expect(fn () => RemovePackageAction::run(
        'vendor/finalization-showcase',
        static fn (): never => throw new RuntimeException('State finalization failed'),
    ))->toThrow(RuntimeException::class, 'State finalization failed');
    expect($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock)
        ->and($this->processes->commands())->toBe([composerRemovalArgv('vendor/finalization-showcase'), composerRecoveryArgv()])
        ->and($this->processes->processes[1]->hasRun())->toBeTrue();
});

it('updates already-direct bundle members and verifies the bundle leaves the lock file', function (): void {
    $bundlePath = '/virtual/transitive-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $filesystem = new BundleComposerFilesystem([
        $composerPath => json_encode(['require' => ['vendor/member' => '^1.1']], JSON_THROW_ON_ERROR),
        $lockPath => json_encode(['packages' => [['name' => 'vendor/showcase'], ['name' => 'vendor/member']]], JSON_THROW_ON_ERROR),
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/member' => '^1.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/member', version: '^1.1');
    CapellCore::registerPackage('vendor/showcase', path: $bundlePath, version: '^1.1');
    $bundle = CapellCore::getPackage('vendor/showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member'];

    $this->processes->push(output: 'Unused bundle removed', onRun: function () use ($filesystem, $lockPath): void {
        $filesystem->contents[$lockPath] = json_encode(['packages' => [['name' => 'vendor/member']]], JSON_THROW_ON_ERROR);
    });

    RemovePackageAction::run('vendor/showcase');

    expect($filesystem->contents[$lockPath])->not->toContain('vendor/showcase')
        ->and($this->processes->commands())->toBe([composerMemberUpdateArgv('vendor/member')])
        ->and($this->processes->processes[0]->hasRun())->toBeTrue();
});

it('restores composer files when a transitive bundle remains locked', function (): void {
    $bundlePath = '/virtual/retained-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/member' => '^1.1']], JSON_THROW_ON_ERROR);
    $originalLock = json_encode(['packages' => [['name' => 'vendor/showcase'], ['name' => 'vendor/member']]], JSON_THROW_ON_ERROR);
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/member' => '^1.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/member', version: '^1.1');
    CapellCore::registerPackage('vendor/showcase', path: $bundlePath, version: '^1.1');
    $bundle = CapellCore::getPackage('vendor/showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member'];

    $this->processes->push(output: 'Nothing changed')->push();

    expect(fn () => RemovePackageAction::run('vendor/showcase'))
        ->toThrow(RuntimeException::class, 'remains installed in composer.lock');
    expect($filesystem->contents[$composerPath])->toBe($originalComposer)
        ->and($filesystem->contents[$lockPath])->toBe($originalLock)
        ->and($this->processes->commands())->toBe([composerMemberUpdateArgv('vendor/member'), composerRecoveryArgv()])
        ->and($this->processes->processes[1]->hasRun())->toBeTrue();
});

it('restores composer files and reports safe operator diagnostics when recovery fails', function (): void {
    $bundlePath = '/virtual/recovery-failing-showcase';
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/recovery-showcase' => '^1.1']], JSON_THROW_ON_ERROR);
    $originalLock = json_encode(['packages' => [['name' => 'vendor/recovery-showcase'], ['name' => 'vendor/recovery-member']]], JSON_THROW_ON_ERROR);
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
        $bundlePath . '/composer.json' => json_encode(['require' => ['vendor/recovery-member' => '^1.1']], JSON_THROW_ON_ERROR),
    ]);
    app()->instance(Filesystem::class, $filesystem);
    CapellCore::registerPackage('vendor/recovery-member', version: '^1.1');
    CapellCore::registerPackage('vendor/recovery-showcase', path: $bundlePath, version: '^1.1');
    $bundle = CapellCore::getPackage('vendor/recovery-showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/recovery-member'];

    $this->processes->push(output: 'Bundle removed', onRun: function () use ($filesystem, $composerPath, $lockPath): void {
        $composer = json_decode($filesystem->contents[$composerPath], true, flags: JSON_THROW_ON_ERROR);
        unset($composer['require']['vendor/recovery-showcase']);
        $filesystem->contents[$composerPath] = json_encode($composer, JSON_THROW_ON_ERROR);
        $filesystem->contents[$lockPath] = json_encode(['packages' => [['name' => 'vendor/recovery-member']]], JSON_THROW_ON_ERROR);
    });

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
    $this->processes->push(exitCode: 1, errorOutput: $recoveryOutput, onRun: function () use ($filesystem, $composerPath, $lockPath): void {
        $filesystem->contents[$composerPath] = '{"corrupted":true}';
        $filesystem->contents[$lockPath] = '{"corrupted":true}';
    });

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
        ->and($filesystem->contents[$lockPath])->toBe($originalLock)
        ->and($this->processes->commands())->toBe([composerRemovalArgv('vendor/recovery-showcase'), composerRecoveryArgv()]);
});

it('wraps recovery process creation setup and timeout failures safely', function (string $failurePoint): void {
    $composerPath = base_path('composer.json');
    $lockPath = base_path('composer.lock');
    $originalComposer = json_encode(['require' => ['vendor/throwing-package' => '^1.1']], JSON_THROW_ON_ERROR);
    $originalLock = json_encode(['packages' => [['name' => 'vendor/throwing-package']]], JSON_THROW_ON_ERROR);
    $filesystem = new BundleComposerFilesystem([
        $composerPath => $originalComposer,
        $lockPath => $originalLock,
    ]);
    app()->instance(Filesystem::class, $filesystem);

    $this->processes->push(output: 'Package removed', onRun: function () use ($filesystem, $composerPath, $lockPath): void {
        $filesystem->contents[$composerPath] = '{"require":[]}';
        $filesystem->contents[$lockPath] = '{"packages":[]}';
    });

    $corrupt = function (string $marker) use ($filesystem, $composerPath, $lockPath): void {
        $filesystem->contents[$composerPath] = '{"corrupted":"' . $marker . '"}';
        $filesystem->contents[$lockPath] = '{"corrupted":"' . $marker . '"}';
    };

    match ($failurePoint) {
        'creation' => $this->processes->push(onMake: function () use ($corrupt): never {
            $corrupt('creation');

            throw new RuntimeException('Recovery factory exposed recovery-factory-secret.');
        }),
        'setup' => $this->processes->push(onSetEnv: function () use ($corrupt): never {
            $corrupt('setup');

            throw new RuntimeException('Recovery setup exposed recovery-setup-secret.');
        }),
        'timeout' => $this->processes->push(onRun: function () use ($corrupt): never {
            $corrupt('timeout');
            $timedProcess = new Process([...capellComposerArgv(), 'install', 'timeout-secret']);
            $timedProcess->setTimeout(300);

            throw new ProcessTimedOutException($timedProcess, ProcessTimedOutException::TYPE_GENERAL);
        }),
        default => throw new InvalidArgumentException('Unknown recovery failure point [' . $failurePoint . '].'),
    };
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
        ->and($filesystem->contents[$lockPath])->toBe($originalLock)
        ->and($this->processes->commands())->toBe([composerRemovalArgv('vendor/throwing-package'), composerRecoveryArgv()]);

    if ($failurePoint === 'timeout') {
        expect($this->processes->processes[1]->getTimeout())->toEqual(300);
    }
})->with(['creation', 'setup', 'timeout']);

it('gives the removal the configured Composer timeout rather than a literal of its own', function (int $configured, int $expected): void {
    config()->set('capell.process.composer.timeout_seconds', $configured);

    RemovePackageAction::run('vendor/timed-package');

    expect($this->processes->processes)->toHaveCount(1);
    expectComposerRemovalProcess($this->processes->processes[0], 'vendor/timed-package', $expected);
})->with([
    'configured value is honoured' => [900, 900],
    'zero falls back to the default' => [0, 600],
]);

it('honours a caller budget and refuses to start when none remains', function (): void {
    RemovePackageAction::run('vendor/package', timeoutSeconds: 17);

    expect($this->processes->processes)->toHaveCount(1);
    expectComposerRemovalProcess($this->processes->processes[0], 'vendor/package', 17);

    $exhausted = FakeProcessFactory::bind();

    expect(fn (): array => RemovePackageAction::run('vendor/package', timeoutSeconds: 0))
        ->toThrow(RuntimeException::class, 'No job time remains');
    $exhausted->assertNothingRan();
});

it('refuses a removal that declares itself an unattended web-triggered Composer write while server-side tooling is off', function (): void {
    config()->set('capell.server_side_tooling', false);

    expect(fn (): array => RemovePackageAction::run('vendor/package', requiresServerSideTooling: true))
        ->toThrow(RuntimeException::class, 'CAPELL_SERVER_SIDE_TOOLING is disabled');
    $this->processes->assertNothingRan();
});
