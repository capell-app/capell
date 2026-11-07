<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Symfony\Component\HttpFoundation\Response;

/** Route middleware that answers the request itself, so nothing after it ever runs. */
final class ShortCircuitMiddleware
{
    public const string BODY = 'short circuit served';

    public function handle(): Response
    {
        return response(self::BODY);
    }
}
