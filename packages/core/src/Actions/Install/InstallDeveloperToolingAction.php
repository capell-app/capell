<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\Process\Process;

class InstallDeveloperToolingAction
{
    use AsFake;
    use AsObject;

    /** @var (callable(array<int, string>, string, array<string, string>|null): Process)|null */
    private static $processFactory;

    /** @var (callable(string, array<string, mixed>): array{0: int, 1: string})|null */
    private static $artisanCaller;

    private static ?string $agentBridgeRepositoryPath = null;

    private static ?string $composerJsonPath = null;

    private static ?string $boostJsonPath = null;

    public static function setProcessFactory(?callable $factory): void
    {
        self::$processFactory = $factory;
    }

    public static function setArtisanCaller(?callable $caller): void
    {
        self::$artisanCaller = $caller;
    }

    public static function setAgentBridgeRepositoryPath(?string $path): void
    {
        self::$agentBridgeRepositoryPath = $path;
    }

    public static function setComposerJsonPath(?string $path): void
    {
        self::$composerJsonPath = $path;
    }

    public static function setBoostJsonPath(?string $path): void
    {
        self::$boostJsonPath = $path;
    }

    public static function resetProcessFactory(): void
    {
        self::$processFactory = null;
    }

    public static function resetArtisanCaller(): void
    {
        self::$artisanCaller = null;
    }

    public static function resetAgentBridgeRepositoryPath(): void
    {
        self::$agentBridgeRepositoryPath = null;
    }

    public static function resetComposerJsonPath(): void
    {
        self::$composerJsonPath = null;
    }

    public static function resetBoostJsonPath(): void
    {
        self::$boostJsonPath = null;
    }

    public function handle(ProgressReporter $reporter, bool $configureBoost): void
    {
        if (resolve(DeveloperToolingInstallationState::class)->isInstalled()) {
            $reporter->report('✓ Laravel Boost and Capell Agent Bridge are already installed.');

            if (! $configureBoost) {
                $reporter->report('✓ Boost configuration skipped. Pass --developer-tooling without --no-boost-install to run boost:install explicitly.');
            }
        } else {
            $this->configureAgentBridgeRepository($reporter);
            $this->configureGithubProtocols($reporter);
            $this->requirePackages($reporter);
            $this->reloadPackageDiscovery($reporter);
        }

        if ($configureBoost) {
            $this->ensureCoreBoostResourcesAreDiscoverable($reporter);
            $this->configureBoost($reporter);
        }
    }

    private function ensureCoreBoostResourcesAreDiscoverable(ProgressReporter $reporter): void
    {
        if (! $this->rootComposerRequires($this->corePackage())) {
            $reporter->step('Ensuring Capell Core is a direct Composer requirement for Boost discovery…');

            $this->runComposerCommand([
                'composer',
                'require',
                $this->corePackage() . ':*',
                '--with-all-dependencies',
                '--no-interaction',
                '--prefer-dist',
            ], $reporter, 300);
        }

        $this->ensureBoostPackageSelection($this->corePackage());
    }

    private function corePackage(): string
    {
        return 'capell-app/core';
    }

    private function requirePackages(ProgressReporter $reporter): void
    {
        $reporter->step('Installing AI / Agent Bridge developer tooling…');

        $command = [
            'composer',
            'require',
            'capell-app/agent-bridge:*',
            '--dev',
            'laravel/boost',
            '--with-all-dependencies',
            '--no-interaction',
            '--prefer-dist',
        ];

        $this->runComposerCommand($command, $reporter, 600);

        $autoloadPath = base_path('vendor/autoload.php');
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        $reporter->report('✓ Installed Laravel Boost and Capell Agent Bridge.');
    }

    private function rootComposerRequires(string $packageName): bool
    {
        $composerJsonPath = self::$composerJsonPath ?? base_path('composer.json');

        if (! is_file($composerJsonPath)) {
            return false;
        }

        $composerJson = json_decode((string) file_get_contents($composerJsonPath), true);

        if (! is_array($composerJson)) {
            return false;
        }

        $requiredPackages = $composerJson['require'] ?? [];
        $developmentPackages = $composerJson['require-dev'] ?? [];

        return (is_array($requiredPackages) && array_key_exists($packageName, $requiredPackages))
            || (is_array($developmentPackages) && array_key_exists($packageName, $developmentPackages));
    }

    private function ensureBoostPackageSelection(string $packageName): void
    {
        $boostJsonPath = self::$boostJsonPath ?? base_path('boost.json');
        $boostConfig = [];

        if (is_file($boostJsonPath)) {
            $decodedConfig = json_decode((string) file_get_contents($boostJsonPath), true);
            $boostConfig = is_array($decodedConfig) ? $decodedConfig : [];
        }

        $configuredPackages = $boostConfig['packages'] ?? [];
        $packages = is_array($configuredPackages) ? array_values(array_filter($configuredPackages, is_string(...))) : [];

        if (! in_array($packageName, $packages, true)) {
            $packages[] = $packageName;
        }

        sort($packages);

        $boostConfig['packages'] = $packages;

        file_put_contents(
            $boostJsonPath,
            json_encode($boostConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );
    }

    private function configureGithubProtocols(ProgressReporter $reporter): void
    {
        $reporter->step('Configuring Composer GitHub protocols…');

        $this->runComposerCommand([
            'composer',
            'config',
            'github-protocols',
            'https',
        ], $reporter, 120);
    }

    private function configureAgentBridgeRepository(ProgressReporter $reporter): void
    {
        $agentBridgePath = $this->localAgentBridgeRepositoryPath();

        if ($agentBridgePath === null) {
            return;
        }

        $reporter->step('Configuring local Agent Bridge Composer repository…');

        $this->runComposerCommand([
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
        ], $reporter, 120);
    }

    private function localAgentBridgeRepositoryPath(): ?string
    {
        $candidates = self::$agentBridgeRepositoryPath !== null ? [self::$agentBridgeRepositoryPath] : [];

        foreach ($candidates as $candidate) {
            $resolvedPath = str_starts_with($candidate, '/')
                ? $candidate
                : base_path($candidate);

            if (is_dir($resolvedPath)) {
                return $resolvedPath;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runComposerCommand(array $command, ProgressReporter $reporter, int $timeout): void
    {
        $processFactory = self::$processFactory
            ?? fn (array $command, string $currentWorkingDirectory, ?array $environment): Process => new Process($command, $currentWorkingDirectory, $environment);
        $process = $processFactory($command, base_path(), ComposerProcessEnvironment::forInstall($_SERVER));
        $process->setTimeout($timeout);

        $process->run(function (string $type, string $buffer) use ($reporter): void {
            foreach (explode("\n", trim($buffer)) as $line) {
                if ($line !== '') {
                    $reporter->report($line);
                }
            }
        });

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput();
            $error = trim($errorOutput !== '' ? $errorOutput : $process->getOutput());

            throw new RuntimeException(sprintf(
                'Failed to run Composer command [%s]: %s',
                implode(' ', $command),
                $error !== '' ? $error : 'Unknown error.',
            ));
        }
    }

    private function reloadPackageDiscovery(ProgressReporter $reporter): void
    {
        $reporter->step('Reloading package discovery…');

        [$exitCode, $output] = $this->callArtisan('package:discover', ['--ansi' => false]);

        if ($output !== '') {
            $reporter->report($output);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf("Command 'package:discover' failed with exit code %d.", $exitCode));
        }

        CapellCore::clearExtensionCache();
    }

    private function configureBoost(ProgressReporter $reporter): void
    {
        $reporter->step('Configuring Laravel Boost Agent Bridge tooling…');

        [$exitCode, $output] = $this->callArtisan('boost:install', [
            '--guidelines' => true,
            '--skills' => true,
            '--mcp' => true,
            '--no-interaction' => true,
        ]);

        if ($output !== '') {
            $reporter->report($output);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf("Command 'boost:install' failed with exit code %d.", $exitCode));
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: string}
     */
    private function callArtisan(string $command, array $parameters): array
    {
        if (self::$artisanCaller !== null) {
            return (self::$artisanCaller)($command, $parameters);
        }

        $exitCode = Artisan::call($command, $parameters);

        return [$exitCode, trim(Artisan::output())];
    }
}
