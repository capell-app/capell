<?php

declare(strict_types=1);

namespace Capell\Installer\Support\Preflight;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Install\InstallMemoryLimit;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class InstallerPreflight
{
    /** @var array<string, array<string, string>> */
    private static array $commandCheckCache = [];

    /** @param array<int, array<string, mixed>> $checks */
    public static function hasBlockingFailures(array $checks): bool
    {
        return collect($checks)->contains(
            fn (array $check): bool => ($check['status'] ?? null) === 'fail'
                && ($check['severity'] ?? 'blocking') === 'blocking',
        );
    }

    /** @param array<int, array<string, mixed>> $checks */
    public static function statusFor(array $checks): string
    {
        if (self::hasBlockingFailures($checks)) {
            return 'fail';
        }

        return collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'warning')
            ? 'warning'
            : 'pass';
    }

    /**
     * @return array{
     *     status: string,
     *     generatedAt: string,
     *     environment: array<string, mixed>,
     *     groups: array{blocking: array<int, array<string, string>>, advisory: array<int, array<string, string>>},
     *     checks: array<int, array<string, string>>
     * }
     */
    public function run(?InstallInputData $inputData = null): array
    {
        $checks = [
            $this->phpVersion(),
            $this->phpMemoryLimit(),
            $this->requiredExtensions(),
            $this->processSupport(),
            $this->phpBinary(),
            $this->composerBinary($inputData),
            $this->gitBinary(),
            $this->writablePath('storage', storage_path()),
            $this->writablePath('bootstrap-cache', base_path('bootstrap/cache')),
            $this->applicationWriteReadiness(),
            $this->publicOutputReadiness(),
            $this->databaseReadiness(),
            $this->cacheStoreReadiness(),
            $this->queueWorkerReadiness(),
        ];

        if ($inputData instanceof InstallInputData && ($inputData->extraPackages !== [] || $inputData->installDeveloperTooling)) {
            $checks[] = $this->composerFileReadiness();
        }

        if ($inputData instanceof InstallInputData && $inputData->extraPackages !== []) {
            $checks[] = $this->selectedPackages('selected-packages', 'Selected package dry-run', $inputData->extraPackages);
        }

        if ($inputData instanceof InstallInputData && $inputData->installDeveloperTooling) {
            $checks[] = $this->selectedPackages(
                'developer-tooling-packages',
                'Developer tooling package dry-run',
                ['capell-app/agent-bridge', 'laravel/boost'],
                true,
            );
        }

        return [
            'status' => self::statusFor($checks),
            'generatedAt' => now()->toIso8601String(),
            'environment' => $this->environment(),
            'groups' => [
                'blocking' => array_values(array_filter(
                    $checks,
                    fn (array $check): bool => ($check['severity'] ?? 'blocking') === 'blocking',
                )),
                'advisory' => array_values(array_filter(
                    $checks,
                    fn (array $check): bool => ($check['severity'] ?? 'blocking') === 'advisory',
                )),
            ],
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        $environment = [
            'php' => PHP_VERSION,
            'memoryLimit' => resolve(InstallMemoryLimit::class)->current(),
            'laravel' => app()->version(),
            'os' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'paths' => [
                'base' => base_path(),
                'storage' => storage_path(),
                'bootstrapCache' => base_path('bootstrap/cache'),
            ],
            'database' => [
                'driver' => config('database.default'),
                'configuredDatabase' => config('database.connections.' . config('database.default') . '.database', ''),
            ],
        ];

        $filamentVersion = $this->installedPackageVersion('filament/filament');
        if ($filamentVersion !== null && $filamentVersion !== '') {
            $environment['filament'] = $filamentVersion;
        }

        $livewireVersion = $this->installedPackageVersion('livewire/livewire');
        if ($livewireVersion !== null && $livewireVersion !== '') {
            $environment['livewire'] = $livewireVersion;
        }

        return $environment;
    }

    private function installedPackageVersion(string $packageName): ?string
    {
        if (! InstalledVersions::isInstalled($packageName)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($packageName)
            ?? InstalledVersions::getVersion($packageName);
    }

    /** @return array<string, string> */
    private function phpVersion(): array
    {
        return $this->check(
            'php-version',
            'PHP version',
            'pass',
            'PHP ' . PHP_VERSION . ' is compatible with Capell.',
        );
    }

    /** @return array<string, string> */
    private function phpMemoryLimit(): array
    {
        $memoryLimit = resolve(InstallMemoryLimit::class);
        $configuredLimit = $memoryLimit->current();

        if (! $memoryLimit->isSatisfied($configuredLimit)) {
            return $this->check(
                'php-memory-limit',
                'PHP memory limit',
                'fail',
                $memoryLimit->failureMessage($configuredLimit),
                'Increase memory_limit to 512M or higher for the web PHP runtime, or run php -d memory_limit=512M artisan capell:install from the terminal.',
            );
        }

        return $this->check(
            'php-memory-limit',
            'PHP memory limit',
            'pass',
            sprintf('PHP memory_limit=%s is available for Capell installation.', $configuredLimit),
        );
    }

    /** @return array<string, string> */
    private function requiredExtensions(): array
    {
        $required = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'json', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml'];
        $missing = array_values(array_filter($required, fn (string $extension): bool => ! extension_loaded($extension)));

        if ($missing === []) {
            return $this->check('php-extensions', 'PHP extensions', 'pass', 'Required PHP extensions are loaded.');
        }

        return $this->check(
            'php-extensions',
            'PHP extensions',
            'fail',
            'Missing PHP extensions: ' . implode(', ', $missing) . '.',
            'Enable the missing extensions in the PHP runtime used by the web server.',
        );
    }

    /** @return array<string, string> */
    private function processSupport(): array
    {
        if (function_exists('proc_open')) {
            return $this->check('process-support', 'Process execution', 'pass', 'PHP can run Composer and Artisan subprocesses.');
        }

        return $this->check(
            'process-support',
            'Process execution',
            'fail',
            'The proc_open function is disabled.',
            'Enable proc_open for the web PHP runtime or run the installer from an environment that allows subprocesses.',
        );
    }

    /** @return array<string, string> */
    private function phpBinary(): array
    {
        $configuredBinary = config('capell-installer.php_binary');
        $configuredBinary = is_string($configuredBinary) && $configuredBinary !== '' ? $configuredBinary : 'php';

        $binary = $this->findExecutable($configuredBinary);

        if ($binary === null) {
            return $this->check(
                'php-binary',
                'PHP binary',
                'warning',
                'The configured PHP binary could not be resolved.',
                'Set CAPELL_SETUP_PHP_BINARY to the CLI php executable used by this app.',
            );
        }

        if ($this->looksLikePhpFpm($binary)) {
            return $this->check(
                'php-binary',
                'PHP binary',
                'warning',
                'The configured PHP binary points at php-fpm, not CLI PHP.',
                'Set CAPELL_SETUP_PHP_BINARY to the php CLI executable, for example /usr/bin/php.',
            );
        }

        return $this->commandCheck('php-binary', 'PHP binary', [$binary, '--version'], required: true);
    }

    /** @return array<string, string> */
    private function composerBinary(?InstallInputData $inputData): array
    {
        $required = $inputData instanceof InstallInputData
            && ($inputData->extraPackages !== [] || $inputData->installDeveloperTooling);
        $binary = $this->findExecutable(config('capell-installer.composer_binary', 'composer'));

        if ($binary === null) {
            return $this->check(
                'composer-binary',
                'Composer',
                $required ? 'fail' : 'warning',
                'Composer is not available to the web PHP process.',
                'Install Composer, set capell-installer.composer_binary, or make composer available on PATH for the web user.',
            );
        }

        return $this->commandCheck('composer-binary', 'Composer', [$binary, '--version'], required: $required);
    }

    /** @return array<string, string> */
    private function gitBinary(): array
    {
        $binary = $this->findExecutable('git');

        if ($binary === null) {
            return $this->check(
                'git-binary',
                'Git',
                'warning',
                'Git is not available to the web PHP process.',
                'Install Git or make git available on PATH. Composer may need Git for source installs.',
            );
        }

        return $this->commandCheck('git-binary', 'Git', [$binary, '--version'], required: false);
    }

    /** @return array<string, string> */
    private function writablePath(string $key, string $path): array
    {
        if (File::isDirectory($path) && is_writable($path)) {
            return $this->check($key . '-writable', $path, 'pass', 'Directory is writable.');
        }

        return $this->check(
            $key . '-writable',
            $path,
            'fail',
            'Directory is not writable by the web PHP process.',
            'Update filesystem permissions for the web user before installing.',
        );
    }

    /** @return array<string, string> */
    private function applicationWriteReadiness(): array
    {
        $paths = [
            base_path('.env'),
            base_path('config/filesystems.php'),
            base_path('config/logging.php'),
            base_path('routes/web.php'),
            app_path('Models/User.php'),
            app_path('Providers/Filament/AdminPanelProvider.php'),
            resource_path('css/filament/admin/theme.css'),
            database_path('migrations'),
        ];

        $blockedPaths = $this->blockedWritablePaths($paths);

        if ($blockedPaths === []) {
            return $this->check(
                'application-files-writable',
                'Application files',
                'pass',
                'Installer-managed app files and migration paths are writable or can be created.',
            );
        }

        return $this->check(
            'application-files-writable',
            'Application files',
            'warning',
            'Some installer-managed app files or directories may not be writable.',
            'Check permissions for: ' . implode(', ', $blockedPaths) . '.',
        );
    }

    /** @return array<string, string> */
    private function composerFileReadiness(): array
    {
        $blockedPaths = $this->blockedWritablePaths([
            base_path('composer.json'),
            base_path('composer.lock'),
        ]);

        if ($blockedPaths === []) {
            return $this->check(
                'composer-files-writable',
                'Composer files',
                'pass',
                'composer.json and composer.lock are writable for package changes.',
            );
        }

        return $this->check(
            'composer-files-writable',
            'Composer files',
            'warning',
            'Selected packages may need to update Composer files.',
            'Check permissions for: ' . implode(', ', $blockedPaths) . '.',
        );
    }

    /** @return array<string, string> */
    private function publicOutputReadiness(): array
    {
        $paths = [
            public_path('page-cache'),
            public_path('vendor/capell-frontend'),
            public_path('build/filament'),
        ];

        $blockedPaths = array_values(array_filter(
            $paths,
            fn (string $path): bool => ! $this->pathIsWritableOrCreatable($path),
        ));

        if ($blockedPaths === []) {
            return $this->check(
                'public-output-writable',
                'Public output',
                'pass',
                'Public page cache and compiled asset paths are writable or can be created.',
            );
        }

        return $this->check(
            'public-output-writable',
            'Public output',
            'warning',
            'Capell needs writable public output for page cache and generated CSS assets.',
            'Make these paths writable by the web user: ' . implode(', ', $blockedPaths) . '. See https://docs.capell.app/packages/frontend/server-config/',
        );
    }

    private function pathIsWritableOrCreatable(string $path): bool
    {
        if (File::exists($path)) {
            return is_writable($path);
        }

        $parentPath = $this->nearestExistingParentPath($path);

        return $parentPath !== null && is_writable($parentPath);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function blockedWritablePaths(array $paths): array
    {
        return array_values(array_filter(
            $paths,
            fn (string $path): bool => ! $this->pathIsWritableOrCreatable($path),
        ));
    }

    private function nearestExistingParentPath(string $path): ?string
    {
        $parentPath = dirname($path);

        while ($parentPath !== '' && $parentPath !== dirname($parentPath)) {
            if (File::exists($parentPath)) {
                return $parentPath;
            }

            $parentPath = dirname($parentPath);
        }

        return File::exists($parentPath) ? $parentPath : null;
    }

    /** @return array<string, string> */
    private function databaseReadiness(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->check('database-connection', 'Database connection', 'pass', 'The configured database connection is reachable.');
        } catch (Throwable $throwable) {
            return $this->check(
                'database-connection',
                'Database connection',
                'warning',
                'The database connection is not currently reachable: ' . $this->clean($throwable->getMessage()),
                'The installer will try to create the configured database. If this still fails, check credentials and CREATE DATABASE permissions.',
            );
        }
    }

    /** @return array<string, string> */
    private function cacheStoreReadiness(): array
    {
        $store = config('cache.default');

        if ($store !== 'database') {
            return $this->check(
                'cache-store',
                'Setup cache store',
                'pass',
                sprintf('CACHE_STORE=%s does not require a database cache table before install.', $store),
            );
        }

        $table = config('cache.stores.database.table', 'cache');

        try {
            if (Schema::hasTable($table)) {
                return $this->check(
                    'cache-store',
                    'Setup cache store',
                    'pass',
                    sprintf('CACHE_STORE=database is usable because the %s table exists.', $table),
                );
            }
        } catch (Throwable $throwable) {
            return $this->check(
                'cache-store',
                'Setup cache store',
                'fail',
                'CACHE_STORE=database could not be validated: ' . $this->clean($throwable->getMessage()),
                'Create the cache table before opening the installer, or temporarily set CACHE_STORE=file or CACHE_STORE=array until migrations have run.',
            );
        }

        return $this->check(
            'cache-store',
            'Setup cache store',
            'fail',
            sprintf('CACHE_STORE=database is configured but the %s table does not exist yet.', $table),
            'Run php artisan cache:table && php artisan migrate, or temporarily set CACHE_STORE=file or CACHE_STORE=array until Capell migrations have run.',
        );
    }

    /** @return array<string, string> */
    private function queueWorkerReadiness(): array
    {
        $connection = config('queue.default', 'sync');

        if ($connection === 'sync') {
            return $this->check(
                'queue-worker',
                'Queue worker',
                'pass',
                'QUEUE_CONNECTION=sync runs installer work in the current request flow.',
            );
        }

        return $this->check(
            'queue-worker',
            'Queue worker',
            'warning',
            sprintf('QUEUE_CONNECTION=%s dispatches installer work to the queue.', $connection),
            'Run a queue worker or configure Supervisor/process management before using background jobs. See https://laravel.com/docs/queues#running-the-queue-worker',
        );
    }

    /**
     * @param  array<int, string>  $packages
     * @return array<string, string>
     */
    private function selectedPackages(string $key, string $label, array $packages, bool $dev = false): array
    {
        $binary = $this->findExecutable(config('capell-installer.composer_binary', 'composer'));

        if ($binary === null) {
            return $this->check(
                $key,
                $label,
                'fail',
                'Composer is required to install selected downloadable packages.',
                'Make Composer available to the web PHP process or deselect downloadable packages.',
            );
        }

        $arguments = array_map(fn (string $package): string => $package . ':*', $packages);
        $options = [
            '--dry-run',
            '--no-audit',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
            '--with-all-dependencies',
        ];

        if ($dev) {
            $options[] = '--dev';
        }

        $process = new Process(
            array_merge([
                $binary,
                'require',
            ], $arguments, $options),
            base_path(),
            ComposerProcessEnvironment::forInstall($_SERVER),
        );
        $process->setTimeout(120);
        $process->run();

        if ($process->isSuccessful()) {
            return $this->check($key, $label, 'pass', 'Selected downloadable packages can be resolved by Composer.');
        }

        return $this->check(
            $key,
            $label,
            'fail',
            $this->clean($process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput()),
            'Check package names, Composer repositories, GitHub access, and HTTPS/SSH clone configuration.',
        );
    }

    /** @param array<int, string> $command */
    private function commandCheck(string $key, string $label, array $command, bool $required): array
    {
        if (! function_exists('proc_open')) {
            return $this->check($key, $label, $required ? 'fail' : 'warning', 'Cannot test command because proc_open is disabled.');
        }

        $cacheKey = hash('sha256', base_path() . '|' . implode("\0", $command) . '|' . ($required ? '1' : '0'));

        if (isset(self::$commandCheckCache[$cacheKey])) {
            return self::$commandCheckCache[$cacheKey];
        }

        try {
            $process = new Process($command, base_path(), ComposerProcessEnvironment::forInstall($_SERVER));
            $process->setTimeout(15);
            $process->run();

            if ($process->isSuccessful()) {
                $firstOutputLine = strtok($this->clean($process->getOutput()), "\n");

                return self::$commandCheckCache[$cacheKey] = $this->check(
                    $key,
                    $label,
                    'pass',
                    trim($firstOutputLine !== false ? $firstOutputLine : 'Command is available.'),
                );
            }

            return self::$commandCheckCache[$cacheKey] = $this->check(
                $key,
                $label,
                $required ? 'fail' : 'warning',
                $this->clean($process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput()),
                'Make sure the command is executable by the web PHP process.',
            );
        } catch (Throwable $throwable) {
            return self::$commandCheckCache[$cacheKey] = $this->check(
                $key,
                $label,
                $required ? 'fail' : 'warning',
                $this->clean($throwable->getMessage()),
                'Make sure the command is executable by the web PHP process.',
            );
        }
    }

    private function findExecutable(string $binary): ?string
    {
        if ($binary !== '' && (is_file($binary) || is_executable($binary))) {
            return $binary;
        }

        return (new ExecutableFinder)->find($binary);
    }

    private function looksLikePhpFpm(string $binary): bool
    {
        $filename = basename($binary);

        return str_contains($filename, 'php-fpm') || str_contains($filename, 'phpfpm');
    }

    private function clean(string $message): string
    {
        $message = preg_replace('/\e\[[0-9;]*m/', '', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (strlen($message) <= 600) {
            return $message;
        }

        return substr($message, 0, 597) . '...';
    }

    /** @return array<string, string> */
    private function check(string $key, string $label, string $status, string $message, string $remediation = ''): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'severity' => $status === 'warning' ? 'advisory' : 'blocking',
            'message' => $message,
            'remediation' => $remediation,
        ];
    }
}
