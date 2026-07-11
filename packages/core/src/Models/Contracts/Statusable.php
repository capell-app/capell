<?php

declare(strict_types=1);

namespace Capell\Core\Models\Contracts;

/**
 * @property int $status
 */
interface Statusable
{
    public function isEnabled(): bool;

    public function isDisabled(): bool;
}
