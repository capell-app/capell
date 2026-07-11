<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Frontend\Actions\GenerateAllErrorPageCachesAction;
use Illuminate\Console\Command;

final class GenerateErrorPagesCommand extends Command
{
    protected $signature = 'capell:generate-error-pages';

    protected $description = 'Generate static error page caches for all enabled Capell sites';

    public function handle(): int
    {
        $total = GenerateAllErrorPageCachesAction::run();

        $this->info(sprintf('Generated static error page caches for %d site(s).', $total));

        return self::SUCCESS;
    }
}
