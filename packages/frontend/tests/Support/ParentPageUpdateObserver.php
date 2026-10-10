<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

final class ParentPageUpdateObserver
{
    public static bool $handled = false;

    public function updated(): void
    {
        self::$handled = true;
    }
}
