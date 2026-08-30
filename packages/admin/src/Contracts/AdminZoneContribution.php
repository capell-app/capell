<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

use Capell\Admin\Data\AdminZoneContextData;
use Capell\Admin\Enums\AdminZone;
use Capell\Core\Support\Extensions\ExtensionPosition;

interface AdminZoneContribution
{
    public function zone(): AdminZone;

    public function key(): string;

    public function position(): ExtensionPosition;

    public function isVisible(AdminZoneContextData $context): bool;

    /** @return list<mixed> */
    public function resolve(AdminZoneContextData $context): array;

    public function owner(): string;

    public function source(): string;
}
