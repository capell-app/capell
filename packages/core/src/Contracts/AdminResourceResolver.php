<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface AdminResourceResolver
{
    public function hasPageResource(string $name = 'default'): bool;

    /** @return class-string|null */
    public function getPageResource(string $name = 'default'): ?string;
}
