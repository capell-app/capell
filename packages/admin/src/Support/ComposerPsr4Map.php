<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Closure;
use Composer\Autoload\ClassLoader;

/** Composer's PSR-4 map, as the registered autoloaders hold it in memory. */
final readonly class ComposerPsr4Map
{
    /** @param  (Closure(): iterable<string, list<string>>)|null  $reader  Reads the map in place of the registered autoloaders. */
    public function __construct(private ?Closure $reader = null) {}

    /**
     * Each registered namespace prefix with its directories, across every registered autoloader.
     *
     * @return iterable<string, list<string>>
     */
    public function prefixes(): iterable
    {
        if ($this->reader instanceof Closure) {
            yield from ($this->reader)();

            return;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            yield from $loader->getPrefixesPsr4();
        }
    }
}
