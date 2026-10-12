<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Install;

use Illuminate\Console\Command;

final class LateInstallTestCommand extends Command
{
    protected $signature = 'test:late-package-install {--value=}';

    public function handle(): int
    {
        $value = $this->option('value');
        $this->line(is_string($value) ? $value : '');

        return self::SUCCESS;
    }
}
