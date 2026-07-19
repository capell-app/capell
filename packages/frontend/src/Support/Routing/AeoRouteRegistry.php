<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Capell\Frontend\Actions\AssertPublicRenderContractAction;
use Capell\Frontend\Contracts\AeoRouteProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class AeoRouteRegistry
{
    private const string PATH_ACTION_KEY = 'capellAeoPath';

    /** @var array<string, AeoRouteProvider> */
    private array $providers = [];

    private bool $routesRegistered = false;

    private bool $taggedProvidersDiscovered = false;

    public function __construct(
        private readonly Container $container,
        private readonly FrontendRouteMiddlewareRegistry $middleware,
        private readonly Router $router,
        /** @var iterable<AeoRouteProvider> */
        private readonly iterable $fallbackProviders = [],
    ) {}

    public function register(AeoRouteProvider $provider): void
    {
        $path = $this->normalizePath($provider->path());

        throw_if(isset($this->providers[$path]), LogicException::class, sprintf(
            'An AEO route provider is already registered for [%s].',
            $path,
        ));

        $this->providers[$path] = $provider;

        if ($this->routesRegistered) {
            $this->registerRoute($path);
        }
    }

    /** @return list<AeoRouteProvider> */
    public function providers(): array
    {
        $this->discoverTaggedProviders();

        return array_values($this->providers);
    }

    public function registerRoutes(): void
    {
        if ($this->routesRegistered) {
            return;
        }

        $this->routesRegistered = true;
        $this->discoverTaggedProviders();

        foreach (array_keys($this->providers) as $path) {
            $this->registerRoute($path);
        }
    }

    public function dispatch(Request $request): Response
    {
        $this->discoverTaggedProviders();

        $route = $request->route();
        $path = $route instanceof Route ? $route->getAction(self::PATH_ACTION_KEY) : null;
        $provider = is_string($path) ? ($this->providers[$path] ?? null) : null;

        abort_unless($provider instanceof AeoRouteProvider, Response::HTTP_NOT_FOUND);

        $response = $provider->handle($request);
        AssertPublicRenderContractAction::run($response);

        return $response;
    }

    private function registerRoute(string $path): void
    {
        $route = $this->router
            ->get($path, [self::class, 'dispatch'])
            ->middleware($this->middleware->all())
            ->withoutMiddleware('frontend.resolve')
            ->name('capell-frontend.aeo.' . str_replace('/', '.', $path));

        $route->setAction(array_replace($route->getAction(), [self::PATH_ACTION_KEY => $path]));
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');

        throw_if(
            preg_match('/^[a-z0-9._\/-]{1,64}$/', $path) !== 1,
            InvalidArgumentException::class,
            'AEO route paths must be 1-64 lowercase URL-safe characters without wildcards.',
        );

        return $path;
    }

    private function discoverTaggedProviders(): void
    {
        if ($this->taggedProvidersDiscovered) {
            return;
        }

        $this->taggedProvidersDiscovered = true;

        foreach ($this->container->tagged(AeoRouteProvider::TAG) as $provider) {
            if ($provider instanceof AeoRouteProvider) {
                $this->register($provider);
            }
        }

        foreach ($this->fallbackProviders as $provider) {
            $path = $this->normalizePath($provider->path());

            if (! isset($this->providers[$path])) {
                $this->register($provider);
            }
        }
    }
}
