<?php

declare(strict_types=1);

use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\Extensions\AdminContributionReceiptPreparer;
use Capell\Core\Contracts\Extensions\PreparesExtensionContributionReceipts;
use Illuminate\Container\Container;

it('is tagged so the extension contract audit prepares the admin runtime', function (): void {
    $preparers = iterator_to_array(app()->tagged(PreparesExtensionContributionReceipts::TAG), false);

    expect(array_map(get_class(...), $preparers))->toContain(AdminContributionReceiptPreparer::class);
});

it('prepares the bound admin runtime so lazily registered bridge receipts exist', function (): void {
    $container = new Container;
    $preparations = 0;
    $container->instance(AdminRuntimeActivator::class, new AdminRuntimeActivator(
        new AdminBridgeRegistry,
        function () use (&$preparations): void {
            $preparations++;
        },
        static function (): void {},
        static function (string $packageName): void {},
    ));

    new AdminContributionReceiptPreparer($container)->prepareContributionReceipts();

    expect($container->make(AdminRuntimeActivator::class)->isPrepared())->toBeTrue()
        ->and($preparations)->toBe(1);
});

it('does nothing when the admin runtime is not bound', function (): void {
    $container = new Container;

    expect(fn () => new AdminContributionReceiptPreparer($container)->prepareContributionReceipts())->not->toThrow(Throwable::class)
        ->and($container->bound(AdminRuntimeActivator::class))->toBeFalse();
});
