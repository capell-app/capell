<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Capell\Frontend\Http\Middleware\RejectReservedFrontendDomains;
use Capell\Frontend\Http\Middleware\RejectReservedFrontendPaths;

final class FrontendRouteMiddlewareRegistry
{
    /** @var list<class-string|string> */
    private array $middleware = [
        RejectReservedFrontendDomains::class,
        RejectReservedFrontendPaths::class,
        'web',
        'frontend.maintenance',
        'workspace.context',
        'frontend.resolve',
        'frontend.anonymous_cacheable_render',
    ];

    /**
     * @param  list<class-string|string>  $middleware
     */
    public function prepend(array $middleware): self
    {
        $this->middleware = $this->merge($middleware, $this->middleware);

        return $this;
    }

    /**
     * @param  list<class-string|string>  $middleware
     */
    public function append(array $middleware): self
    {
        $this->middleware = $this->merge($this->middleware, $middleware);

        return $this;
    }

    /**
     * @param  list<class-string|string>  $middleware
     */
    public function insertBefore(string $existingMiddleware, array $middleware): self
    {
        $position = array_search($existingMiddleware, $this->middleware, true);

        if ($position === false) {
            return $this->prepend($middleware);
        }

        $this->middleware = $this->merge(
            [
                ...array_slice($this->middleware, 0, $position),
                ...$middleware,
                ...array_slice($this->middleware, $position),
            ],
            [],
        );

        return $this;
    }

    /**
     * @param  list<class-string|string>  $middleware
     */
    public function insertAfter(string $existingMiddleware, array $middleware): self
    {
        $position = array_search($existingMiddleware, $this->middleware, true);

        if ($position === false) {
            return $this->append($middleware);
        }

        $this->middleware = $this->merge(
            [
                ...array_slice($this->middleware, 0, $position + 1),
                ...$middleware,
                ...array_slice($this->middleware, $position + 1),
            ],
            [],
        );

        return $this;
    }

    /**
     * @return list<class-string|string>
     */
    public function all(): array
    {
        return $this->middleware;
    }

    /**
     * @param  list<class-string|string>  $first
     * @param  list<class-string|string>  $second
     * @return list<class-string|string>
     */
    private function merge(array $first, array $second): array
    {
        return array_values(collect([...$first, ...$second])
            ->unique()
            ->values()
            ->all());
    }
}
