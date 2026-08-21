<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\RuntimeRole\Unknown;

use Illuminate\Support\ServiceProvider;
use Override;

final class UnknownAuthoringRuntimeRoleProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('runtime-role.fixture.unknown-authoring', true);
    }
}
