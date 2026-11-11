<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Frontend\Support\Cache\FragmentCache;
use Illuminate\Console\Command;

/**
 * Retires every cached fragment by rotating the fragment cache namespace.
 * Entries rebuild on the next request, so unlike a store-wide cache clear it
 * cannot leave the application unable to boot. A release switch or rollback
 * runs it so fragments rendered by the other release are never served.
 */
final class FlushFragmentCacheCommand extends Command
{
    protected $signature = 'capell:flush-fragment-cache';

    protected $description = 'Retire every cached frontend fragment; they rebuild on the next request';

    public function handle(FragmentCache $fragments): int
    {
        $fragments->flush();

        $this->components->info('Fragment cache flushed.');

        return self::SUCCESS;
    }
}
