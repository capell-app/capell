<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\InstallDeveloperToolingAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\NullProgressReporter;

beforeEach(function (): void {
    InstallDeveloperToolingAction::resetProcessFactory();
    InstallDeveloperToolingAction::resetArtisanCaller();
    InstallDeveloperToolingAction::resetAgentBridgeRepositoryPath();
    InstallDeveloperToolingAction::resetComposerJsonPath();
    InstallDeveloperToolingAction::resetBoostJsonPath();
    bindInstallDeveloperToolingState(false);
});

afterEach(function (): void {
    InstallDeveloperToolingAction::resetProcessFactory();
    InstallDeveloperToolingAction::resetArtisanCaller();
    InstallDeveloperToolingAction::resetAgentBridgeRepositoryPath();
    InstallDeveloperToolingAction::resetComposerJsonPath();
    InstallDeveloperToolingAction::resetBoostJsonPath();
});

function bindInstallDeveloperToolingState(bool $installed): void
{
    app()->instance(DeveloperToolingInstallationState::class, new class($installed) extends DeveloperToolingInstallationState
    {
        public function __construct(private readonly bool $installed) {}

        public function isInstalled(): bool
        {
            return $this->installed;
        }
    });
}

it('requires boost and capell agent-bridge then configures boost tooling', function (): void {
    $commands = [];
    $composerJsonPath = tempnam(sys_get_temp_dir(), 'capell-composer-');
    $boostJsonPath = tempnam(sys_get_temp_dir(), 'capell-boost-');
    file_put_contents($composerJsonPath, json_encode([
        'require' => [
            'capell-app/core' => '*',
        ],
    ], JSON_THROW_ON_ERROR));
    $agentBridgePath = sys_get_temp_dir() . '/capell-agent-bridge-test';
    if (! is_dir($agentBridgePath)) {
        mkdir($agentBridgePath, 0777, true);
    }

    InstallDeveloperToolingAction::setAgentBridgeRepositoryPath($agentBridgePath);
    InstallDeveloperToolingAction::setComposerJsonPath($composerJsonPath);
    InstallDeveloperToolingAction::setBoostJsonPath($boostJsonPath);

    InstallDeveloperToolingAction::setProcessFactory(function (array $command, string $cwd, ?array $environment) use (&$commands): object {
        $commands[] = $command;

        return new class
        {
            public function setTimeout(?float $timeout): self
            {
                return $this;
            }

            public function run(?callable $callback = null): int
            {
                if ($callback !== null) {
                    $callback('out', "Installing developer tooling\n");
                }

                return 0;
            }

            public function isSuccessful(): bool
            {
                return true;
            }

            public function getOutput(): string
            {
                return 'Installed';
            }

            public function getErrorOutput(): string
            {
                return '';
            }
        };
    });

    $artisanCalls = [];
    InstallDeveloperToolingAction::setArtisanCaller(function (string $command, array $parameters) use (&$artisanCalls): array {
        $validOptions = [
            'package:discover' => ['--ansi'],
            'boost:install' => ['--guidelines', '--skills', '--mcp', '--no-interaction'],
        ];
        $unsupportedOptions = array_diff(array_keys($parameters), $validOptions[$command] ?? []);

        expect($unsupportedOptions)->toBe([]);

        $artisanCalls[] = [$command, $parameters];

        return [0, ''];
    });
    CapellCore::shouldReceive('clearExtensionCache')->once();

    InstallDeveloperToolingAction::run(new NullProgressReporter, configureBoost: true);

    expect($commands)->toContain([
        'composer',
        'config',
        '--json',
        'repositories.capell-agent-bridge',
        json_encode([
            'type' => 'path',
            'url' => $agentBridgePath,
            'options' => [
                'symlink' => true,
            ],
        ], JSON_THROW_ON_ERROR),
    ])->toContain([
        'composer',
        'config',
        'github-protocols',
        'https',
    ])->toContain([
        'composer',
        'require',
        'capell-app/agent-bridge:*',
        '--dev',
        'laravel/boost',
        '--with-all-dependencies',
        '--no-interaction',
        '--prefer-dist',
    ]);

    expect($artisanCalls)->toBe([
        ['package:discover', ['--ansi' => false]],
        ['boost:install', [
            '--guidelines' => true,
            '--skills' => true,
            '--mcp' => true,
            '--no-interaction' => true,
        ]],
    ]);

    expect(json_decode((string) file_get_contents($boostJsonPath), true)['packages'])->toContain('capell-app/core');

    @unlink($composerJsonPath);
    @unlink($boostJsonPath);
});

it('skips boost configuration when disabled', function (): void {
    InstallDeveloperToolingAction::setProcessFactory(fn (array $command, string $cwd, ?array $environment): object => new class
    {
        public function setTimeout(?float $timeout): self
        {
            return $this;
        }

        public function run(?callable $callback = null): int
        {
            return 0;
        }

        public function isSuccessful(): bool
        {
            return true;
        }

        public function getOutput(): string
        {
            return 'Installed';
        }

        public function getErrorOutput(): string
        {
            return '';
        }
    });

    $artisanCalls = [];
    InstallDeveloperToolingAction::setArtisanCaller(function (string $command, array $parameters) use (&$artisanCalls): array {
        $artisanCalls[] = [$command, $parameters];

        return [0, ''];
    });
    CapellCore::shouldReceive('clearExtensionCache')->once();

    InstallDeveloperToolingAction::run(new NullProgressReporter, configureBoost: false);

    expect($artisanCalls)->toBe([
        ['package:discover', ['--ansi' => false]],
    ]);
});

it('requires capell core directly before configuring boost when missing from root composer', function (): void {
    bindInstallDeveloperToolingState(true);

    $composerJsonPath = tempnam(sys_get_temp_dir(), 'capell-composer-');
    $boostJsonPath = tempnam(sys_get_temp_dir(), 'capell-boost-');
    file_put_contents($composerJsonPath, json_encode([
        'require' => [
            'php' => '^8.3',
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($boostJsonPath, json_encode([
        'packages' => [
            'filament/filament',
        ],
    ], JSON_THROW_ON_ERROR));

    InstallDeveloperToolingAction::setComposerJsonPath($composerJsonPath);
    InstallDeveloperToolingAction::setBoostJsonPath($boostJsonPath);

    $commands = [];
    InstallDeveloperToolingAction::setProcessFactory(function (array $command, string $cwd, ?array $environment) use (&$commands): object {
        $commands[] = $command;

        return new class
        {
            public function setTimeout(?float $timeout): self
            {
                return $this;
            }

            public function run(?callable $callback = null): int
            {
                return 0;
            }

            public function isSuccessful(): bool
            {
                return true;
            }

            public function getOutput(): string
            {
                return 'Installed';
            }

            public function getErrorOutput(): string
            {
                return '';
            }
        };
    });

    $artisanCalls = [];
    InstallDeveloperToolingAction::setArtisanCaller(function (string $command, array $parameters) use (&$artisanCalls): array {
        $artisanCalls[] = [$command, $parameters];

        return [0, ''];
    });

    InstallDeveloperToolingAction::run(new NullProgressReporter, configureBoost: true);

    expect($commands)->toBe([
        [
            'composer',
            'require',
            'capell-app/core:*',
            '--with-all-dependencies',
            '--no-interaction',
            '--prefer-dist',
        ],
    ])->and($artisanCalls)->toBe([
        ['boost:install', [
            '--guidelines' => true,
            '--skills' => true,
            '--mcp' => true,
            '--no-interaction' => true,
        ]],
    ]);

    expect(json_decode((string) file_get_contents($boostJsonPath), true)['packages'])->toBe([
        'capell-app/core',
        'filament/filament',
    ]);

    @unlink($composerJsonPath);
    @unlink($boostJsonPath);
});

it('skips composer requirements when developer tooling packages are already installed', function (): void {
    bindInstallDeveloperToolingState(true);

    $composerJsonPath = tempnam(sys_get_temp_dir(), 'capell-composer-');
    $boostJsonPath = tempnam(sys_get_temp_dir(), 'capell-boost-');
    file_put_contents($composerJsonPath, json_encode([
        'require' => [
            'capell-app/core' => '*',
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($boostJsonPath, json_encode([
        'packages' => [
            'capell-app/core',
        ],
    ], JSON_THROW_ON_ERROR));

    InstallDeveloperToolingAction::setComposerJsonPath($composerJsonPath);
    InstallDeveloperToolingAction::setBoostJsonPath($boostJsonPath);

    InstallDeveloperToolingAction::setProcessFactory(function (): object {
        throw new RuntimeException('Composer should not run for installed developer tooling.');
    });

    $artisanCalls = [];
    InstallDeveloperToolingAction::setArtisanCaller(function (string $command, array $parameters) use (&$artisanCalls): array {
        $artisanCalls[] = [$command, $parameters];

        return [0, ''];
    });

    InstallDeveloperToolingAction::run(new NullProgressReporter, configureBoost: true);

    expect($artisanCalls)->toBe([
        ['boost:install', [
            '--guidelines' => true,
            '--skills' => true,
            '--mcp' => true,
            '--no-interaction' => true,
        ]],
    ]);

    @unlink($composerJsonPath);
    @unlink($boostJsonPath);
});

it('skips boost configuration for already installed developer tooling unless requested', function (): void {
    bindInstallDeveloperToolingState(true);

    InstallDeveloperToolingAction::setProcessFactory(function (): object {
        throw new RuntimeException('Composer should not run for installed developer tooling.');
    });

    InstallDeveloperToolingAction::setArtisanCaller(function (): array {
        throw new RuntimeException('Boost install should not run unless explicitly requested.');
    });

    $reporter = new class implements ProgressReporter
    {
        /** @var list<string> */
        public array $messages = [];

        public function step(string $label): void {}

        public function report(string $message): void
        {
            $this->messages[] = $message;
        }

        public function error(string $line): void {}
    };

    InstallDeveloperToolingAction::run($reporter, configureBoost: false);

    expect($reporter->messages)
        ->toContain('✓ Laravel Boost and Capell Agent Bridge are already installed.')
        ->toContain('✓ Boost configuration skipped. Pass --developer-tooling without --no-boost-install to run boost:install explicitly.');
});
