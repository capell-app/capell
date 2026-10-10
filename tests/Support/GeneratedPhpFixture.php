<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use RuntimeException;

final class GeneratedPhpFixture
{
    /**
     * Load a generated class in a fresh namespace so repeated patches never reuse
     * a previously loaded provider or model from another fixture. Model relation
     * tests must bind that isolated class to their auth provider and morph map.
     *
     * @template T of object
     *
     * @param  class-string<T>  $type
     * @return T
     */
    public static function load(string $path, string $type, mixed ...$arguments): object
    {
        $source = file_get_contents($path);
        throw_if($source === false, RuntimeException::class, 'Cannot read generated PHP fixture.');

        $namespace = 'Capell\\Tests\\Generated\\Fixture' . bin2hex(random_bytes(8));
        if (preg_match('/namespace\s+[^;{]+;/', $source) === 1) {
            $source = preg_replace('/namespace\s+[^;{]+;/', 'namespace ' . str_replace('\\', '\\\\', $namespace) . ';', $source, 1);
        } else {
            $source = preg_replace('/(<\?php\s*(?:declare\s*\([^)]*\)\s*;)?)/', '$1 namespace ' . str_replace('\\', '\\\\', $namespace) . ';', $source, 1);
        }

        throw_unless(is_string($source), RuntimeException::class, 'Cannot isolate generated PHP fixture.');

        $code = preg_replace('/^\s*<\?php/', '', $source, 1);
        throw_unless(is_string($code), RuntimeException::class, 'Cannot load generated PHP fixture.');

        eval($code);
        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, $namespace . '\\')) {
                $instance = new $class(...$arguments);
                throw_unless($instance instanceof $type, RuntimeException::class, 'Generated fixture does not implement its expected API.');

                return $instance;
            }
        }

        throw new RuntimeException('Generated fixture declares no class.');
    }
}
