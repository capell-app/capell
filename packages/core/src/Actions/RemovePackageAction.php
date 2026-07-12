<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static array{package: string, status: string, message: string, output: string, cache_cleared: bool} run(string $name, ?callable $finalize = null)
 */
class RemovePackageAction
{
    use AsObject;

    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array{package: string, status: string, message: string, output: string, cache_cleared: bool}
     */
    public function handle(string $name, ?callable $finalize = null): array
    {
        $this->clearPackageManifestCacheFiles();

        $composerPath = base_path('composer.json');
        $lockPath = base_path('composer.lock');
        $originalComposer = $this->files->exists($composerPath) ? $this->files->get($composerPath) : null;
        $originalLock = $this->files->exists($lockPath) ? $this->files->get($lockPath) : null;
        $command = ['composer', 'remove', $name, '--no-interaction', '--no-scripts'];
        $composerSucceeded = false;

        try {
            $bundleUpdate = $this->prepareBundleDeletion($name, $composerPath, $originalComposer);
            if ($bundleUpdate['complete']) {
                return $this->success($name, 'Bundle requirements were already promoted.');
            }

            if ($bundleUpdate['update_members'] !== []) {
                $command = [
                    'composer',
                    'update',
                    ...$bundleUpdate['update_members'],
                    '--with-dependencies',
                    '--no-interaction',
                    '--no-scripts',
                ];
            }

            $process = $this->processFactory->make($command, base_path());

            $process->setEnv(ComposerProcessEnvironment::forInstall($_SERVER));
            $process->setTimeout(300);
            $process->run();

            $this->clearPackageManifestCacheFiles();

            $errorOutput = $process->getErrorOutput();
            $standardOutput = $process->getOutput();

            throw_unless($process->isSuccessful(), RuntimeException::class, $this->safeComposerFailureMessage());
            $composerSucceeded = true;

            throw_if(($standardOutput === '' || $standardOutput === '0') && ($errorOutput === '' || $errorOutput === '0'), RuntimeException::class, sprintf("Package '%s' removal produced no output.", $name));

            $this->assertPackageAbsentFromLock($name, $lockPath);
            if ($finalize !== null) {
                $finalize();
            }

            return $this->success($name, $standardOutput);
        } catch (Throwable $throwable) {
            $this->restoreComposerFiles($composerPath, $lockPath, $originalComposer, $originalLock);

            if ($composerSucceeded) {
                try {
                    $this->recoverComposerInstallation($composerPath, $lockPath, $originalComposer, $originalLock);
                } catch (Throwable) {
                    $this->restoreComposerFiles($composerPath, $lockPath, $originalComposer, $originalLock);

                    throw new RuntimeException('Composer files were restored after package removal failed, but the installed package graph could not be recovered. '
                    . 'Composer output was withheld because it may contain credentials. Installed dependencies may not match composer.lock. '
                    . 'Run "composer install --no-interaction --no-scripts" from the application root in a trusted terminal.', $throwable->getCode(), previous: $throwable);
                }
            }

            throw $throwable;
        }
    }

    private function safeComposerFailureMessage(): string
    {
        return 'Composer could not complete the package removal. Composer output was withheld because it may contain credentials. '
            . 'Run the removal from the application root in a trusted terminal, resolve the reported Composer error, then retry.';
    }

    private function assertPackageAbsentFromLock(string $name, string $lockPath): void
    {
        if (! $this->files->exists($lockPath)) {
            return;
        }

        $lock = json_decode($this->files->get($lockPath), true, flags: JSON_THROW_ON_ERROR);
        throw_unless(is_array($lock), RuntimeException::class, 'The application composer.lock file is invalid.');

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = is_array($lock[$section] ?? null) ? $lock[$section] : [];

            foreach ($packages as $package) {
                if (is_array($package) && ($package['name'] ?? null) === $name) {
                    throw new RuntimeException(sprintf("Package '%s' remains installed in composer.lock.", $name));
                }
            }
        }
    }

    private function recoverComposerInstallation(
        string $composerPath,
        string $lockPath,
        ?string $composerContents,
        ?string $lockContents,
    ): void {
        $process = $this->processFactory->make(
            ['composer', 'install', '--no-interaction', '--no-scripts'],
            base_path(),
        );
        $process->setEnv(ComposerProcessEnvironment::forInstall($_SERVER));
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->restoreComposerFiles($composerPath, $lockPath, $composerContents, $lockContents);

            throw new RuntimeException(
                'Composer could not restore the package installation automatically. Composer output was withheld because it may contain credentials. '
                . 'Run composer install from the application root in a trusted terminal before retrying.',
            );
        }
    }

    /**
     * @return array{complete: bool, update_members: list<string>}
     */
    private function prepareBundleDeletion(string $name, string $composerPath, ?string $composerContents): array
    {
        if (! CapellCore::hasPackage($name) || CapellCore::getPackage($name)->getKind() !== 'bundle') {
            return ['complete' => false, 'update_members' => []];
        }

        throw_if($composerContents === null, RuntimeException::class, 'The application composer.json file is unavailable.');

        $bundle = CapellCore::getPackage($name);
        $composer = json_decode($composerContents, true, flags: JSON_THROW_ON_ERROR);
        throw_unless(is_array($composer), RuntimeException::class, 'The application composer.json file is invalid.');

        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $requireDev = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
        $bundleWasDirect = array_key_exists($name, $require) || array_key_exists($name, $requireDev);
        $constraints = $this->bundleMemberConstraints($bundle->path);
        $promoted = [];

        foreach ($bundle->getRequirements() as $memberName) {
            if (array_key_exists($memberName, $require)) {
                continue;
            }

            if (array_key_exists($memberName, $requireDev)) {
                continue;
            }

            $require[$memberName] = $constraints[$memberName] ?? '^0.0';
            $promoted[] = $memberName;
        }

        if (! $bundleWasDirect && $promoted === []) {
            return ['complete' => false, 'update_members' => array_values($bundle->getRequirements())];
        }

        ksort($require);
        $composer['require'] = $require;
        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $this->files->replace($composerPath, $encoded);

        return ['complete' => false, 'update_members' => $bundleWasDirect ? [] : $promoted];
    }

    /** @return array<string, string> */
    private function bundleMemberConstraints(?string $packagePath): array
    {
        if ($packagePath === null || ! $this->files->exists($packagePath . '/composer.json')) {
            return [];
        }

        $composer = json_decode($this->files->get($packagePath . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return array_filter($require, static fn (mixed $constraint, mixed $package): bool => is_string($package) && is_string($constraint), ARRAY_FILTER_USE_BOTH);
    }

    private function restoreComposerFiles(
        string $composerPath,
        string $lockPath,
        ?string $composerContents,
        ?string $lockContents,
    ): void {
        if ($composerContents !== null) {
            $this->files->replace($composerPath, $composerContents);
        }

        if ($lockContents !== null) {
            $this->files->replace($lockPath, $lockContents);
        } elseif ($this->files->exists($lockPath)) {
            $this->files->delete($lockPath);
        }
    }

    /** @return array{package: string, status: string, message: string, output: string, cache_cleared: bool} */
    private function success(string $name, string $output): array
    {
        CapellCore::clearExtensionCache();

        return [
            'package' => $name,
            'status' => 'removed',
            'message' => sprintf("Package '%s' removed successfully.", $name),
            'output' => $output,
            'cache_cleared' => true,
        ];
    }

    private function clearPackageManifestCacheFiles(): void
    {
        $paths = [
            base_path('bootstrap/cache/capell-package-manifests.php'),
            base_path('bootstrap/cache/capell-theme-chain.php'),
            base_path('bootstrap/cache/packages.php'),
            base_path('bootstrap/cache/services.php'),
        ];

        if ($this->shouldPreserveLaravelPackageManifestCacheFiles()) {
            $paths = array_values(array_filter(
                $paths,
                fn (string $path): bool => ! in_array(basename($path), ['packages.php', 'services.php'], true),
            ));
        }

        $this->files->delete($paths);
    }

    /**
     * Under Testbench the application boots from a skeleton whose cached package manifests the
     * test harness relies on. Match the skeleton by name rather than by its vendor path, because
     * parallel test processes boot from per-process copies of it.
     */
    private function shouldPreserveLaravelPackageManifestCacheFiles(): bool
    {
        return app()->runningUnitTests()
            && str_contains(app()->bootstrapPath(), 'testbench');
    }
}
