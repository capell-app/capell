<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Psr4TestDeclarationScanner
{
    /**
     * Read declarations without loading test files or changing Composer's autoloader.
     *
     * @return list<array{file: string, line: int, class: string, expected: list<string>}>
     */
    public function scan(string $root): array
    {
        $resolvedRoot = realpath($root);
        throw_if($resolvedRoot === false, RuntimeException::class, 'Missing repository directory: ' . $root);

        $root = $resolvedRoot;
        $manifests = [$root . '/composer.json', ...(glob($root . '/packages/*/composer.json') ?: [])];
        $violations = [];

        foreach ($manifests as $manifestPath) {
            $manifest = json_decode($this->read($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $mappings = $manifest['autoload-dev']['psr-4'] ?? [];
            $files = [];
            $directories = [];

            foreach ($mappings as $prefix => $paths) {
                foreach ((array) $paths as $path) {
                    $directory = realpath(dirname($manifestPath) . '/' . $path);
                    if ($directory === false || ! is_dir($directory)) {
                        throw new RuntimeException('Missing PSR-4 directory: ' . dirname($manifestPath) . '/' . $path);
                    }

                    $directories[$prefix][] = $directory;
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                        if ($file->isFile() && $file->getExtension() === 'php') {
                            $files[$file->getPathname()] = true;
                        }
                    }
                }
            }

            foreach (array_keys($files) as $file) {
                foreach ($this->declarations($this->read($file)) as $declaration) {
                    $expected = [];
                    foreach ($directories as $prefix => $paths) {
                        if (! str_starts_with($declaration['class'], (string) $prefix)) {
                            continue;
                        }

                        foreach ($paths as $directory) {
                            $expected[] = $directory . '/' . str_replace('\\', '/', substr($declaration['class'], strlen((string) $prefix))) . '.php';
                        }
                    }

                    if (in_array($file, $expected, true)) {
                        continue;
                    }

                    $relative = substr((string) $file, strlen($root) + 1);
                    $key = $relative . ':' . $declaration['class'];
                    $violations[$key] = [
                        'file' => $relative,
                        'line' => $declaration['line'],
                        'class' => $declaration['class'],
                        'expected' => array_map(static fn (string $path): string => substr($path, strlen($root) + 1), $expected),
                    ];
                }
            }
        }

        ksort($violations);

        return array_values($violations);
    }

    /** @return list<array{class: string, line: int}> */
    public function declarations(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $namespace = '';
        $depth = 0;
        $namespaceDepth = null;
        $declarations = [];

        foreach ($tokens as $index => $token) {
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                if ($namespaceDepth === $depth) {
                    $namespace = '';
                    $namespaceDepth = null;
                }

                $depth--;
            } elseif (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($next = $index + 1; isset($tokens[$next]) && is_array($tokens[$next]); $next++) {
                    $namespace .= $tokens[$next][1];
                }

                $namespaceDepth = ($tokens[$next] ?? null) === '{' ? $depth + 1 : null;
            } elseif (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $name = $tokens[$index + 1] ?? null;
                // Anonymous classes and Foo::class have no following name token.
                if (is_array($name) && $name[0] === T_STRING) {
                    $declarations[] = ['class' => ltrim($namespace . '\\' . $name[1], '\\'), 'line' => $token[2]];
                }
            }
        }

        return $declarations;
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        throw_if($source === false, RuntimeException::class, 'Cannot read ' . $path);

        return $source;
    }
}
