<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Upgrade;

use Illuminate\Console\Command;

/** Stands in for the HTML cache package's command that marks stored public HTML stale. */
final class HtmlCacheClearCommand extends Command
{
    public int $runs = 0;

    protected $signature = 'capell:html-cache:clear';

    public function __construct(private readonly int $exitCode = self::SUCCESS)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->runs++;

        return $this->exitCode;
    }
}
