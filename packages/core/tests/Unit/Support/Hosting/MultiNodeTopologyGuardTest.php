<?php

declare(strict_types=1);

use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;

it('refuses node-local installer state in a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.driver', 'file');

    expect(function (): void {
        (new MultiNodeTopologyGuard)->assertCacheStoreIsShared('The web installer');
    })
        ->toThrow(RuntimeException::class, 'The web installer cannot run while CAPELL_MULTI_NODE=true');
});

it('allows shared installer state in a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis.driver', 'redis');

    (new MultiNodeTopologyGuard)->assertCacheStoreIsShared('The web installer');

    expect(true)->toBeTrue();
});
