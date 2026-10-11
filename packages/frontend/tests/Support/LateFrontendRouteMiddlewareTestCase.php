<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Tests\FrontendTestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Override;
use RuntimeException;

abstract class LateFrontendRouteMiddlewareTestCase extends FrontendTestCase
{
    #[Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        throw_unless($app instanceof Application, RuntimeException::class, 'Expected the Testbench application.');
        $app->make(Repository::class)->set('capell-frontend.register_home_route', true);

        $app->booting(static function (Application $app): void {
            $app->make(FrontendRouteMiddlewareRegistry::class)->prepend(['test.frontend.early']);

            $provider = $app->getProvider(FrontendServiceProvider::class);
            throw_unless($provider instanceof FrontendServiceProvider, RuntimeException::class, 'Expected the Frontend provider.');

            // Reproduce optional providers contributing after Frontend loads its routes.
            $provider->booted(static function () use ($app): void {
                $app->make(FrontendRouteMiddlewareRegistry::class)
                    ->insertBefore('frontend.resolve', ['test.frontend.late-before'])
                    ->insertAfter('frontend.anonymous_cacheable_render', ['test.frontend.late-after']);
            });
        });
    }
}
