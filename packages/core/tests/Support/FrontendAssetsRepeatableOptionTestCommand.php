<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Console\Commands\Concerns\HasFrontendAssetsOption;
use Illuminate\Console\Command;

/**
 * Declares `--assets` the way `capell:admin-setup` does, so the value reaches the
 * trait as the array Symfony builds rather than as a hand-fed string.
 */
final class FrontendAssetsRepeatableOptionTestCommand extends Command
{
    use HasFrontendAssetsOption;

    protected $signature = 'capell:test-frontend-assets-repeatable-option {--assets=*}';

    protected $description = 'Test repeatable frontend asset option resolution.';

    public function handle(): int
    {
        $this->line(json_encode($this->getFrontendAssets(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
