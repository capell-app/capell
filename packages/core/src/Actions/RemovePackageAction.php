<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static array{package: string, status: string, message: string, output: string, cache_cleared: bool} run(string $name, ?callable $finalize = null, bool $requiresServerSideTooling = false, ?int $timeoutSeconds = null)
 */
class RemovePackageAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
        private readonly Filesystem $files,
        private readonly ReleaseRootWriteGuard $releaseRootWriteGuard = new ReleaseRootWriteGuard,
    ) {}

    /**
     * @return array{package: string, status: string, message: string, output: string, cache_cleared: bool}
     */
    public function handle(
        string $name,
        ?callable $finalize = null,
        bool $requiresServerSideTooling = false,
        ?int $timeoutSeconds = null,
    ): array {
        throw_if($timeoutSeconds !== null && $timeoutSeconds < 1, RuntimeException::class, 'No job time remains for the Composer package removal.');

        $this->assertReleaseRootWritable($requiresServerSideTooling);
        $this->clearPackageManifestCacheFiles();

        $snapshot = ComposerStateSnapshot::capture($this->files);
        $composerPath = $snapshot->composerPath;
        $lockPath = $snapshot->lockPath;
        $originalComposer = $snapshot->composerContents;
        $composer = new RuntimeBinaryResolver()->composer();
        $command = [...$composer, 'remove', $name, '--no-interaction', '--no-scripts', '--no-audit', '--no-progress'];
        $composerSucceeded = false;

        try {
            $bundleUpdate = $this->prepareBundleDeletion($name, $composerPath, $originalComposer);
            if ($bundleUpdate['complete']) {
                return $this->success($name, 'Bundle requirements were already promoted.');
            }

            if ($bundleUpdate['update_members'] !== []) {
                $command = [
                    ...$composer,
                    'update',
                    ...$bundleUpdate['update_members'],
                    '--with-dependencies',
                    '--no-interaction',
                    '--no-scripts',
                    // Parity with the Marketplace install runner: a non-interactive
                    // run should not spend its timeout on output nobody reads.
                    '--no-audit',
                    '--no-progress',
                ];
            }

            $process = $this->processFactory->make($command, base_path());

            $process->setEnv(ComposerProcessEnvironment::forInstall($_SERVER));
            $process->setTimeout($timeoutSeconds ?? $this->composerTimeoutSeconds());
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
            $snapshot->restoreFiles();

            if ($composerSucceeded) {
                try {
                    $snapshot->restoreInstalledPackages($this->processFactory);
                } catch (Throwable) {
                    $snapshot->restoreFiles();

                    throw new RuntimeException('Composer files were restored after package removal failed, but the installed package graph could not be recovered. '
                    . 'Composer output was withheld because it may contain credentials. Installed dependencies may not match composer.lock. '
                    . 'Run "composer install --no-interaction --no-scripts" from the application root in a trusted terminal.', $throwable->getCode(), previous: $throwable);
                }
            }

            throw $throwable;
        }
    }

    /**
     * A removal rewrites composer.json, composer.lock and vendor/, and deletes
     * cached manifests under bootstrap/cache, so it is exactly as destructive to
     * an immutable release root as an install is. The install path has always
     * refused those hosts; without this the same host would silently permit the
     * removal, and the asymmetry is the bug.
     *
     * CAPELL_SERVER_SIDE_TOOLING is a property of the call site rather than of
     * the removal, so the caller decides. It gates unattended Composer writes
     * driven by an HTTP request — the admin panel's package deletion — and must
     * stay false for `capell:install` and the uninstall command, which an
     * operator triggers directly and which would otherwise force every operator
     * to set the variable.
     */
    private function assertReleaseRootWritable(bool $requiresServerSideTooling): void
    {
        $this->releaseRootWriteGuard->assertWritable(
            operation: 'Removing a package with Composer',
            relativePaths: ['composer.json', 'composer.lock', 'vendor', 'bootstrap/cache'],
            requiresServerSideTooling: $requiresServerSideTooling,
        );
    }

    /**
     * The same budget every other Composer run on this host gets.
     *
     * Read from `capell.process.composer.timeout_seconds` rather than carrying a
     * literal of its own: a removal is the same kind of work as an install, on
     * the same network, against the same vendor directory, and the queued
     * uninstall runs it inside a job whose whole timeout chain is derived from
     * this key. A second, smaller number here would have made the removal the
     * one Composer operation that timed out on a host the operator had already
     * tuned — and it would have done so 300 seconds into an uninstall that had
     * already run the extension's lifecycle.
     */
    private function composerTimeoutSeconds(): int
    {
        $configured = config('capell.process.composer.timeout_seconds', 600);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 600;
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

            $require[$memberName] = $constraints[$memberName] ?? '^1.0';
            $promoted[] = $memberName;
        }

        if (! $bundleWasDirect && $promoted === []) {
            return ['complete' => false, 'update_members' => array_values($bundle->getRequirements())];
        }

        ksort($require);
        $composer['require'] = $require;
        $encoded = JsonCodec::encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
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

    /**
     * Composer runs with --no-scripts here too, so nothing replays the
     * application's post-autoload-dump chain after a removal.
     *
     * The two manifests that would name the removed package's providers —
     * Laravel's packages.php and services.php — are deleted here, which is the
     * part that would otherwise fatal the next request.
     *
     * The rest was left open by Task 2 and is settled as follows.
     *
     * The replay itself belongs to the caller, not here. Capell cannot
     * enumerate an application's post-autoload-dump chain — the application is
     * a different repository — and replaying it means running the host's own
     * scripts in a subprocess with a time budget, which this action has no
     * business owning: `capell:install` and `capell:extension-uninstall` are run
     * by an operator whose terminal is about to run Composer properly anyway,
     * and the admin panel's in-request path has no budget to spend. The one
     * caller that both needs it and can afford it is the queued uninstall, and
     * RunMarketplaceUninstallAttemptJob does it there, on the same shared
     * implementation an install uses. So the gap is closed where it matters and
     * not paid for where it does not.
     *
     * bootstrap/cache/config.php is deliberately still left alone. A removed
     * package leaves stale values behind rather than references to classes that
     * no longer exist, so it does not fatal; dropping a host's cached config as
     * a side effect of a package removal is a larger behaviour change than the
     * risk warrants, and the queued path's health check boots a fresh process
     * against exactly that cached config before declaring success — so a host
     * where it genuinely mattered fails loudly and rolls back rather than
     * silently carrying stale values. Republishing or clearing it stays the
     * operator's deploy step.
     *
     * Assets a removed plugin published into public/ are also left. They are
     * inert files, deleting them cannot be done safely — nothing records which
     * of them the package actually put there, and a wrong guess deletes an
     * operator's own file — and the same deploy step that rebuilds the config
     * cache is where a host that cares about them cleans up.
     */
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
