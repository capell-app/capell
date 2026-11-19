<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Diagnostics;

enum ServerSettingFlag: string
{
    case ValidateTimestamps = 'validate_timestamps';
    case OpcacheMemory = 'opcache_memory';
    case OpcacheInternedStrings = 'opcache_interned_strings';
    case OpcacheMaxFiles = 'opcache_max_files';
    case FpmMaxChildren = 'fpm_max_children';
    case SwapPressure = 'swap_pressure';
    case IdleQueueWorkers = 'idle_queue_workers';
}
