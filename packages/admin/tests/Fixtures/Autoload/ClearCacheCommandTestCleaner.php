<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Cache\AdminCacheCleaner;

final class ClearCacheCommandTestCleaner implements AdminCacheCleaner
{
    /** @var list<string> */
    public static array $executionOrder = [];

    public function clear(): void
    {
        self::$executionOrder[] = 'tagged-cache';
    }
}
