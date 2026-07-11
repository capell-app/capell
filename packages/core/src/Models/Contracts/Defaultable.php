<?php

declare(strict_types=1);

namespace Capell\Core\Models\Contracts;

/**
 * @property bool $default
 */
interface Defaultable
{
    public static function getDefault(): ?self;

    public function isDefault(): bool;
}
