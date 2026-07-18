<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Support\CapellCoreManager;
use Illuminate\Contracts\Container\Container;

final readonly class OptionalCompanions
{
    public function __construct(
        private CapellCoreManager $core,
        private Container $container,
    ) {}

    /**
     * @param  list<class-string>  $requiredClasses
     */
    public function installed(string $package, array $requiredClasses = []): bool
    {
        if (! $this->core->isPackageInstalled($package)) {
            return false;
        }

        return array_all(
            $requiredClasses,
            fn (string $requiredClass): bool => class_exists($requiredClass),
        );
    }

    public function service(string $contract): ?object
    {
        if (! $this->container->bound($contract)) {
            return null;
        }

        return $this->container->make($contract);
    }
}
