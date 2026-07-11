<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Throwable;

/**
 * Test double for RunInstallAction that captures received input.
 */
class FakeRunInstallAction
{
    public InstallInputData $capturedInput;

    public int $callCount = 0;

    public ?Throwable $throwable = null;

    public function handle(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        $this->capturedInput = $inputData;
        $this->callCount++;

        if ($this->throwable instanceof Throwable) {
            throw $this->throwable;
        }
    }
}
