<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\AdminHelpEntryData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/** @extends AbstractKeyedRegistry<AdminHelpEntryData> */
final class AdminHelpRegistry extends AbstractKeyedRegistry
{
    public function register(string $route, AdminHelpEntryData $entry): void
    {
        $this->setItem($route, $entry);
    }

    public function get(string $route): ?AdminHelpEntryData
    {
        return $this->getItem($route);
    }
}
