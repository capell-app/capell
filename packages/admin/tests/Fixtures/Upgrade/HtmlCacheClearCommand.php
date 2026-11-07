<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Upgrade;

use Illuminate\Console\Command;

/**
 * Stands in for the HTML cache package's clear command, with its signature: marks every stored page stale,
 * and with --process regenerates them in this process through process-stale.
 */
final class HtmlCacheClearCommand extends Command
{
    protected $signature = 'capell:html-cache:clear {slug?} {--recursive} {--process}';

    public function handle(StoredPublicPages $pages): int
    {
        foreach (array_keys($pages->pages) as $url) {
            $pages->pages[$url] = 'stale';
        }

        $this->info(sprintf('Marked %d URL(s) stale. Nothing has been regenerated yet; the old HTML is still being served.', count($pages->pages)));

        return $this->option('process') === true ? $this->call('capell:html-cache:process-stale') : self::SUCCESS;
    }
}
