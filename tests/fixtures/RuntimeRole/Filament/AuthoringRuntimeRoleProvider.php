<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\RuntimeRole\Filament;

use Illuminate\Support\ServiceProvider;
use Override;

final class AuthoringRuntimeRoleProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('runtime-role.fixture.authoring', true);
    }
}
