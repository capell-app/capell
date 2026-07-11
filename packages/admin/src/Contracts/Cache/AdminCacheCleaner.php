<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Cache;

interface AdminCacheCleaner
{
    public const string TAG = 'capell.admin.cache_cleaner';

    public function clear(): void;
}
