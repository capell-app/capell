<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

interface StaticMaintenancePageStore
{
    public function exists(string $file): bool;

    public function path(string $file): ?string;

    public function put(string $file, string $contents): void;
}
