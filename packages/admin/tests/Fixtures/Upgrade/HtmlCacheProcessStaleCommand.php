<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Upgrade;

use Illuminate\Console\Command;

/** Stands in for the HTML cache package's process-stale command: regenerates stale pages, purging each from the edge. */
final class HtmlCacheProcessStaleCommand extends Command
{
    protected $signature = 'capell:html-cache:process-stale {--limit=} {--suppress-inline-edge-purge}';

    public function handle(StoredPublicPages $pages): int
    {
        $succeeded = 0;
        $failed = 0;
        foreach ($pages->pages as $url => $state) {
            if ($state !== 'stale') {
                continue;
            }

            if (in_array($url, $pages->failing, true)) {
                $failed++;

                continue;
            }

            $pages->pages[$url] = 'regenerated';
            $pages->edgePurges[] = $url;
            $succeeded++;
        }

        $summary = sprintf('Refreshed %d stale URL(s): %d succeeded, %d failed.', $succeeded + $failed, $succeeded, $failed);
        $failed === 0 ? $this->info($summary) : $this->error($summary);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
