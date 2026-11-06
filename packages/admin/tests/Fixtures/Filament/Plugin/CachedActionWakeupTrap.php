<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

final class CachedActionWakeupTrap
{
    public static bool $restored = false;

    public function __wakeup(): void
    {
        self::$restored = true;
    }
}
