<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Fixtures;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class WidgetShowcaseComposerConsumer
{
    public const string BUNDLE = 'capell-app/widget-showcase';

    public const string TRANSITIVE_DEPENDENCY = 'capell-app/widget-shared-runtime';

    /** @var list<string> */
    public const array MEMBERS = [
        'capell-app/widget-audio-playlist',
        'capell-app/widget-before-after',
        'capell-app/widget-content-reveal',
        'capell-app/widget-countdown',
        'capell-app/widget-data-chart',
        'capell-app/widget-hotspots',
        'capell-app/widget-live-poll',
        'capell-app/widget-location-map',
        'capell-app/widget-slideshow',
        'capell-app/widget-youtube',
    ];

    /** @var list<string> */
    public const array ALREADY_DIRECT_MEMBERS = [
        'capell-app/widget-content-reveal',
        'capell-app/widget-hotspots',
    ];

    private function __construct(
        public readonly string $rootPath,
        private readonly Filesystem $files,
    ) {}

    public static function create(): self
    {
        $fixture = new self(
            sys_get_temp_dir() . '/capell-widget-showcase-consumer-' . bin2hex(random_bytes(8)),
            new Filesystem,
        );

        try {
            $fixture->build();
        } catch (Throwable $throwable) {
            $fixture->destroy();

            throw $throwable;
        }

        return $fixture;
    }

    public function destroy(): void
    {
        if ($this->files->isDirectory($this->rootPath)) {
            $this->files->deleteDirectory($this->rootPath);
        }
    }

    public function composerContents(): string
    {
        return $this->files->get($this->rootPath . '/composer.json');
    }

    public function lockContents(): string
    {
        return $this->files->get($this->rootPath . '/composer.lock');
    }

    /** @return array<string, string> */
    public function directRequirements(): array
    {
        $composer = $this->decode($this->composerContents());
        $requirements = $composer['require'] ?? [];

        throw_unless(is_array($requirements), RuntimeException::class, 'The clean consumer require map is invalid.');

        /** @var array<string, string> $requirements */
        return $requirements;
    }

    /** @return list<string> */
    public function lockedPackageNames(): array
    {
        $lock = $this->decode($this->lockContents());
        $names = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? [];
            if (! is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (is_array($package) && is_string($package['name'] ?? null)) {
                    $names[] = $package['name'];
                }
            }
        }

        sort($names);

        return $names;
    }

    public function packagePath(string $packageName): string
    {
        return $this->rootPath . '/packages/' . Str::after($packageName, '/');
    }

    public function hasInstalledPackage(string $packageName): bool
    {
        return $this->files->isDirectory($this->rootPath . '/vendor/' . $packageName);
    }

    public function validateComposerFiles(): void
    {
        $process = new Process([
            'composer',
            'validate',
            '--no-check-publish',
            '--no-interaction',
        ], $this->rootPath, $this->composerEnvironment());
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $output = $process->getErrorOutput() ?: $process->getOutput();

            throw new RuntimeException(
                'The offline widget-showcase consumer has inconsistent Composer files: '
                    . Str::limit(Str::squish($output), 1000, '…'),
            );
        }
    }

    /** @return array<string, string> */
    public function composerEnvironment(): array
    {
        $path = getenv('PATH');

        return [
            'BITBUCKET_TOKEN' => '',
            'COMPOSER' => $this->rootPath . '/composer.json',
            'COMPOSER_AUTH' => '{}',
            'COMPOSER_CACHE_DIR' => $this->rootPath . '/composer-cache',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_HOME' => $this->rootPath . '/composer-home',
            'COMPOSER_NO_AUDIT' => '1',
            'COMPOSER_PROCESS_TIMEOUT' => '30',
            'GITHUB_TOKEN' => '',
            'GIT_TERMINAL_PROMPT' => '0',
            'GITLAB_TOKEN' => '',
            'HOME' => $this->rootPath,
            'PACKAGIST_TOKEN' => '',
            'PATH' => is_string($path) && $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin',
            'SSH_AUTH_SOCK' => '',
        ];
    }

    private function build(): void
    {
        $this->files->makeDirectory($this->rootPath . '/packages', 0755, true);
        $this->files->makeDirectory($this->rootPath . '/composer-home', 0755, true);

        $this->writePackage(self::TRANSITIVE_DEPENDENCY, '1.0.0');

        foreach (self::MEMBERS as $memberName) {
            $requirements = in_array($memberName, [
                'capell-app/widget-content-reveal',
                'capell-app/widget-youtube',
            ], true)
                ? [self::TRANSITIVE_DEPENDENCY => '1.0.0']
                : [];

            $this->writePackage($memberName, '1.1.0', $requirements);
        }

        $bundleRequirements = ['php' => '^8.4'];
        foreach (self::MEMBERS as $memberName) {
            $bundleRequirements[$memberName] = '^1.0';
        }

        $this->writePackage(self::BUNDLE, '1.1.0', $bundleRequirements);
        $this->writeJson($this->rootPath . '/composer.json', [
            'name' => 'capell-tests/widget-showcase-consumer',
            'type' => 'project',
            'license' => 'proprietary',
            'repositories' => [
                ['packagist.org' => false],
                [
                    'type' => 'path',
                    'url' => 'packages/*',
                    'options' => ['symlink' => false],
                ],
            ],
            'require' => [
                'capell-app/widget-content-reveal' => '1.1.0',
                'capell-app/widget-hotspots' => '^1.1',
                self::BUNDLE => '^1.1',
            ],
            'config' => [
                'allow-plugins' => false,
                'sort-packages' => true,
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ]);

        $process = new Process([
            'composer',
            'update',
            '--no-interaction',
            '--no-scripts',
            '--no-plugins',
            '--no-progress',
            '--no-audit',
            '--prefer-dist',
        ], $this->rootPath, $this->composerEnvironment());
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $output = $process->getErrorOutput() ?: $process->getOutput();

            throw new RuntimeException(
                'Unable to initialise the offline widget-showcase Composer consumer: '
                    . Str::limit(Str::squish($output), 1000, '…'),
            );
        }
    }

    /** @param array<string, string> $requirements */
    private function writePackage(string $name, string $version, array $requirements = []): void
    {
        $path = $this->packagePath($name);
        $this->files->makeDirectory($path, 0755, true);
        $this->writeJson($path . '/composer.json', [
            'name' => $name,
            'description' => 'Deterministic offline Composer integration fixture.',
            'version' => $version,
            'type' => 'library',
            'license' => 'proprietary',
            'require' => $requirements,
        ]);
    }

    /** @param array<string, mixed> $contents */
    private function writeJson(string $path, array $contents): void
    {
        $this->files->put(
            $path,
            json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $contents): array
    {
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        throw_unless(is_array($decoded), RuntimeException::class, 'The clean consumer Composer file is invalid.');

        return $decoded;
    }
}
