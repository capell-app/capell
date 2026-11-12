<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Livewire;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Override;

/**
 * Livewire's persistent middleware replay, made to stop on any middleware answer.
 *
 * Livewire replays the rendering route's persistent middleware before an update, but it
 * only honours middleware that throws or redirects: a returned 403 (or a 200 returned
 * without calling the next middleware) is discarded and the update runs anyway. This
 * replacement runs the same pipeline and, unless the request reaches the end of it
 * and comes back unchanged, ends the update with the exact response the middleware gave.
 *
 * Mirrors Livewire 4.4 `PersistentMiddleware::applyPersistentMiddleware()`; the
 * behaviour tests through the update endpoint pin that contract across upgrades.
 */
final class ShortCircuitingPersistentMiddleware extends PersistentMiddleware
{
    /**
     * Livewire registers mechanisms under their own class name; this one stands in for
     * the original so the `Livewire` facade and the boot loop resolve it.
     */
    #[Override]
    public function register(): void
    {
        app()->instance(PersistentMiddleware::class, $this);
    }

    #[Override]
    protected function applyPersistentMiddleware(): void
    {
        $routeKey = $this->method . '|' . $this->path;

        // Consecutive snapshots from the same route (a parent and its lazy children)
        // are replayed once, as Livewire does, so explicit bindings resolve once.
        if ($this->lastMiddlewareRoute === $routeKey) {
            return;
        }

        $request = $this->makeFakeRequest();
        $middleware = $this->getApplicablePersistentMiddleware($request);

        if (is_null($middleware) || $middleware === []) {
            return;
        }

        $passedThrough = new Response;

        $response = new Pipeline(app())
            ->send($request)
            ->through($middleware)
            ->then(static fn (): Response => $passedThrough);

        if ($response !== $passedThrough) {
            throw new HttpResponseException(Router::toResponse($request, $response));
        }

        $this->lastMiddlewareRoute = $routeKey;

        if ($route = $request->route()) {
            foreach ($route->parameters() as $parameter) {
                if ($parameter instanceof Model) {
                    $this->resolvedRouteModels[$parameter::class . ':' . $parameter->getKey()] = $parameter;
                }
            }
        }
    }
}
