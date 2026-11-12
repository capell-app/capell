<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Illuminate\Http\Response;

/** Refuses by returning a response instead of aborting, without calling the next middleware. */
final class RuntimeForbiddenResponseMiddleware
{
    public function handle(): Response
    {
        return new Response('refused by panel middleware', \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN, ['X-Refused-By' => 'panel']);
    }
}
