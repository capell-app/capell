<?php

declare(strict_types=1);

use Capell\Admin\Support\AdminEventRegistry;
use Capell\Admin\Tests\Unit\Fixtures\DummyComponent;
use Capell\Admin\Tests\Unit\Fixtures\DummyHandler;

it('registers and retrieves class-scoped events', function (): void {
    $registry = new AdminEventRegistry;

    $registry->register(DummyComponent::class, 'foo', DummyHandler::class);

    expect($registry->allForClass(DummyComponent::class))
        ->toHaveKey('foo', DummyHandler::class)
        ->and($registry->has(DummyComponent::class, 'foo'))
        ->toBeTrue();

    $registry->unregister(DummyComponent::class, 'foo');

    expect($registry->has(DummyComponent::class, 'foo'))->toBeFalse();
});
