<?php

declare(strict_types=1);

/** Derive distribution inputs from manifests, runtime trees and local source paths. */
final class RepositoryDistributionFiles
{
    /** @var list<string> */
    public readonly array $packagePaths;

    public function __construct(private readonly string $root)
    {
        /** @var list<array{path: string}> $packages */
        $packages = json_decode($this->read('config/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->packagePaths = array_column($packages, 'path');
    }

    /** @return list<string> */
    public function requiredFiles(): array
    {
        $required = ['composer.json', 'SECURITY.md', 'LICENSE.md'];

        foreach ($this->packagePaths as $package) {
            foreach (['composer.json', 'capell.json', 'SECURITY.md', 'LICENSE.md'] as $file) {
                $required[] = $package . '/' . $file;
            }

            // These trees are loaded by providers, publication, rendering and makers.
            // In particular, tests inside extension stubs are runtime generator inputs.
            foreach (['src', 'resources', 'publishes', 'database', 'config', 'routes', 'stubs'] as $directory) {
                array_push($required, ...$this->files($package . '/' . $directory));
            }

            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($this->read($package . '/capell.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($this->manifestPaths($manifest) as $path) {
                $candidate = $package . '/' . $path;
                array_push($required, ...($this->files($candidate) ?: [$candidate]));
            }

            foreach ($this->files($package . '/src') as $source) {
                if (! str_ends_with($source, '.php')) {
                    continue;
                }

                $contents = $this->read($source);
                foreach (token_get_all($contents) as $token) {
                    if (! is_array($token)) {
                        continue;
                    }

                    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                        continue;
                    }

                    $path = substr($token[1], 1, -1);
                    if (! str_starts_with($path, 'docs/')) {
                        continue;
                    }

                    // Marketplace resolves these relative to any installed package,
                    // not just the package containing the reader. Also retain local
                    // documentation named by runtime remediation messages.
                    foreach (['', ...$this->packagePaths] as $scope) {
                        $candidate = ($scope === '' ? '' : $scope . '/') . $path;
                        if (is_file($this->root . '/' . $candidate)) {
                            $required[] = $candidate;
                        }
                    }
                }

                preg_match_all('/__DIR__\s*\.\s*([\'"])([^\'"]+)\1/', $contents, $matches);
                foreach ($matches[2] as $path) {
                    $resolved = realpath($this->root . '/' . dirname($source) . $path);
                    if ($resolved !== false && str_starts_with($resolved, $this->root . '/')) {
                        array_push($required, ...$this->files(substr($resolved, strlen($this->root) + 1)));
                    }
                }
            }
        }

        // A referenced asset directory is runtime data, including siblings added
        // later. Never ignore an ancestor: git archive cannot re-include its files.
        foreach ($required as $file) {
            if (str_contains($file, '/docs/assets/')) {
                array_push($required, ...$this->files(dirname($file)));
            }
        }

        $required = array_values(array_unique($required));
        sort($required);

        return $required;
    }

    /**
     * @param  list<string>  $required
     */
    public function documentationAttributes(string $scope, array $required): string
    {
        $prefix = $scope === '' ? '' : $scope . '/';
        $excluded = $this->unusedDocumentation($prefix . 'docs', $required);

        return implode('', array_map(
            static fn (string $path): string => '/' . substr($path, strlen($prefix)) . " export-ignore\n",
            $excluded,
        ));
    }

    /** @return list<string> */
    private function files(string $path): array
    {
        $absolute = $this->root . '/' . $path;
        if (is_file($absolute)) {
            return [$path];
        }

        if (! is_dir($absolute)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return list<string>
     */
    private function manifestPaths(array $manifest): array
    {
        $paths = [];
        foreach ($manifest as $key => $value) {
            if (is_array($value)) {
                array_push($paths, ...$this->manifestPaths($value));
            } elseif (is_string($value) && ! str_contains($value, '://') && (
                $key === 'path' || preg_match('/^[\w.\/-]+\.[a-zA-Z][a-zA-Z0-9]*$/', $value) === 1
            )) {
                $paths[] = str_starts_with($value, './') ? substr($value, 2) : $value;
            }
        }

        return $paths;
    }

    /**
     * @param  list<string>  $required
     * @return list<string>
     */
    private function unusedDocumentation(string $directory, array $required): array
    {
        if (! is_dir($this->root . '/' . $directory)) {
            return [];
        }

        $excluded = [];
        foreach (scandir($this->root . '/' . $directory) ?: [] as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            $needed = array_filter($required, static fn (string $file): bool => $file === $path || str_starts_with($file, $path . '/'));
            if ($needed === []) {
                $excluded[] = $path;
            } elseif (is_dir($this->root . '/' . $path)) {
                array_push($excluded, ...$this->unusedDocumentation($path, $required));
            }
        }

        return $excluded;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read distribution input ' . $path);
        }

        return $contents;
    }
}
