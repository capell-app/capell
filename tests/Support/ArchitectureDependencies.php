<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Pest\Arch\Repositories\ObjectsRepository;

final class ArchitectureDependencies
{
    /**
     * Use Pest's parsed dependencies and prefix exclusions. Index forbidden
     * names up front so each namespace is scanned once for the whole rule.
     *
     * @param  list<string>  $namespaces  Composer namespace prefixes to inspect
     * @param  list<string>  $symbols  Forbidden function names or namespace prefixes
     * @param  list<string>  $ignored  Excluded class or dependency name prefixes
     */
    public static function assertNoForbiddenUses(array $namespaces, array $symbols, array $ignored): void
    {
        $repository = ObjectsRepository::getInstance();
        $forbidden = [];
        foreach ($symbols as $symbol) {
            foreach ($repository->allByNamespace($symbol) as $dependency) {
                if (! str_starts_with($dependency->name, $symbol)) {
                    continue;
                }

                if (self::isIgnored($dependency->name, $ignored)) {
                    continue;
                }

                $forbidden[$dependency->name] = true;
            }
        }

        $violations = [];
        foreach (array_unique($namespaces) as $namespace) {
            foreach ($repository->allByNamespace($namespace, false) as $object) {
                if (! str_starts_with($object->name, $namespace)) {
                    continue;
                }

                if (self::isIgnored($object->name, $ignored)) {
                    continue;
                }

                foreach ($object->uses as $dependency) {
                    if (isset($forbidden[$dependency])) {
                        $violations[] = sprintf('%s uses %s in %s', $object->name, $dependency, $object->path);
                    }
                }
            }
        }

        expect(array_values(array_unique($violations)))->toBe([]);
    }

    /** @param list<string> $ignored */
    private static function isIgnored(string $name, array $ignored): bool
    {
        return array_any($ignored, static fn (string $prefix): bool => str_starts_with($name, $prefix));
    }
}
