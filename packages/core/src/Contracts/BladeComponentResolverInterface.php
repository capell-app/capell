<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface BladeComponentResolverInterface
{
    /**
     * @return array<string, class-string>
     */
    public function getClassComponentAliases(): array;

    /**
     * @return array<string, string>
     */
    public function getClassComponentNamespaces(): array;
}
