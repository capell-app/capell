<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Install;

use Illuminate\Support\ServiceProvider;
use Override;

final class MidInstallBoostTestProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->commands([MidInstallBoostTestCommand::class]);
    }
}
