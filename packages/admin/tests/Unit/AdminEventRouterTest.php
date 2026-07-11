<?php

declare(strict_types=1);

use Capell\Admin\Support\AdminEventRegistry;
use Capell\Admin\Support\AdminEventRouter;
use Capell\Admin\Tests\Unit\Fixtures\RouterDummyComponent;
use Capell\Admin\Tests\Unit\Fixtures\RouterDummyHandler;
use Illuminate\Container\Container;

it('routes events to registered handlers', function (): void {
    $registry = new AdminEventRegistry;
    $registry->register(RouterDummyComponent::class, 'foo', RouterDummyHandler::class);

    $container = new Container;
    $handler = new RouterDummyHandler;
    $container->instance(RouterDummyHandler::class, $handler);

    $router = new AdminEventRouter($container, $registry);

    $component = new RouterDummyComponent;
    $payload = [1];

    $router->handle('foo', $payload, $component);

    expect($handler)->handled->toBeTrue()
        ->payload->toBe($payload)
        ->componentClass->toBe(RouterDummyComponent::class);
});
