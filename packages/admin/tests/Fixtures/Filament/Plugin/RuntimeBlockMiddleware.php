<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Symfony\Component\HttpFoundation\Response;

class RuntimeBlockMiddleware
{
    public function handle(): Response
    {
        abort(403);
    }
}
