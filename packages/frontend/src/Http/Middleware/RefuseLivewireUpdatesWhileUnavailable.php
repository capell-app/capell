<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Holds public Livewire updates to the same lockdown and maintenance decision as the page that rendered them.
 *
 * `frontend.maintenance` guards the page request, but a component update is a separate POST to the Livewire
 * endpoint, outside the frontend route group. Livewire replays only middleware registered as persistent, from
 * the route that rendered the component, so this one is registered that way and asks the page middleware itself
 * rather than keeping a second copy of its rules. Livewire discards a response a replayed middleware returns and
 * honours only one that throws, so any answer other than "carry on" ends the update as an exception carrying
 * that exact response.
 */
final readonly class RefuseLivewireUpdatesWhileUnavailable
{
    public function __construct(private ServeStaticMaintenancePage $maintenance) {}

    /** @param Closure(Request): SymfonyResponse $next */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // The page render already passed the page middleware; only the replay for an update needs asking again.
        if (! resolve(HandleRequests::class)->isLivewireRoute()) {
            return $next($request);
        }

        $passed = new Response;
        $response = $this->maintenance->handle($request, static fn (): SymfonyResponse => $passed);

        throw_if($response !== $passed, HttpResponseException::class, $response);

        return $next($request);
    }
}
