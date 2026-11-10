<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Capell\Core\Console\Commands\InstallCommand;
use Override;
use Symfony\Component\Console\Command\Command;

/** An installer whose wizard fails without writing any explanation. */
final class SilentFailureInstallCommand extends InstallCommand
{
    public int $wizardRuns = 0;

    #[Override]
    protected function runWizard(): int
    {
        $this->wizardRuns++;

        return Command::FAILURE;
    }
}
