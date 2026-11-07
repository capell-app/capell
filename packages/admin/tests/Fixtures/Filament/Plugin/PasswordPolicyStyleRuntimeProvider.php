<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Illuminate\Support\ServiceProvider;
use Override;

/** Mirrors password-policy: adds a redirecting authentication middleware while the panel boots. */
final class PasswordPolicyStyleRuntimeProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->tag([LateSecurityPanelExtender::class], AdminPanelExtender::TAG);
    }
}
