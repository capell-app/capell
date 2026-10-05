<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Symfony\Component\HttpFoundation\Response;

final class LateSecurityMiddleware
{
    public function handle(): Response
    {
        return redirect('/admin/change-password');
    }
}
