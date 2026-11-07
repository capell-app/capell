<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Upgrade;

use Illuminate\Console\Command;

/** Stands in for the HTML cache package's edge purge readiness check. */
final class HtmlCacheEdgePurgeVerifyCommand extends Command
{
    protected $signature = 'capell:html-cache:edge-purge:verify {--driver=}';

    public function handle(StoredPublicPages $pages): int
    {
        if (! $pages->edgeReady) {
            $this->error('The edge purge driver has no API token configured.');

            return self::FAILURE;
        }

        $this->info('Edge purge configuration is ready (driver: fixture, required: yes). No purge request was sent.');

        return self::SUCCESS;
    }
}
