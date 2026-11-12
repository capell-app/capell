<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Illuminate\Http\Response;

/** Answers with a successful status without calling the next middleware. */
final class RuntimeHeldResponseMiddleware
{
    public function handle(): Response
    {
        return new Response('held by panel middleware', \Symfony\Component\HttpFoundation\Response::HTTP_OK);
    }
}
