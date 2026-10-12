<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Install;

use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

final class FailingLateInstallTestProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->commands([LateInstallTestCommand::class]);

        throw new RuntimeException('Late provider failed.');
    }
}
