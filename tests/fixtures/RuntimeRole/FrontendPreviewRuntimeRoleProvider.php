<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\RuntimeRole;

use Illuminate\Support\ServiceProvider;
use Override;

final class FrontendPreviewRuntimeRoleProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('runtime-role.fixture.frontend', true);
    }
}
