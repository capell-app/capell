<?php

declare(strict_types=1);

use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Tests\Support\RuntimeReadiness\DeferredReceiptRegistry;
use Capell\Core\Tests\Support\RuntimeReadiness\MainDeferredReceiptProvider;
use Capell\Core\Tests\Support\RuntimeReadiness\OrdinaryDeferredReceiptProvider;

it('retains the declaring package when another package resolves a deferred registry', function (string $providerClass): void {
    $receipts = resolve(ExtensionContributionReceiptRegistry::class);
    $provider = match ($providerClass) {
        MainDeferredReceiptProvider::class => new MainDeferredReceiptProvider(app()),
        OrdinaryDeferredReceiptProvider::class => new OrdinaryDeferredReceiptProvider(app()),
        default => throw new InvalidArgumentException('Unknown deferred receipt provider fixture.'),
    };
    $owner = ExtensionContributionReceiptContext::forPackage('test/deferred-owner', 'runtime', $providerClass);
    $consumer = ExtensionContributionReceiptContext::forPackage('test/resolving-consumer', 'runtime', DeferredReceiptRegistry::class);
    app()->bind('test.deferred-receipts', DeferredReceiptRegistry::class);
    app()->bind('test.nested-receipts', DeferredReceiptRegistry::class);

    $receipts->withContexts([$owner], function () use ($provider): void {
        $provider->deferReceipt('test.deferred-receipts', 'outer', function () use ($provider): void {
            $provider->deferReceipt('test.nested-receipts', 'nested');
        });
    });
    expect($receipts->activeContexts())->toBe([]);

    $receipts->withContexts([$consumer], function () use ($receipts, $consumer): void {
        resolve('test.deferred-receipts');
        resolve('test.nested-receipts');
        expect($receipts->activeContexts())->toBe([$consumer]);
        resolve(DeferredReceiptRegistry::class)->record('consumer');
    });

    expect(array_column($receipts->forPackage('test/deferred-owner'), 'key'))->toBe(['outer', 'nested'])
        ->and(array_column($receipts->forPackage('test/resolving-consumer'), 'key'))->toBe(['consumer'])
        ->and($receipts->activeContexts())->toBe([]);
})->with([MainDeferredReceiptProvider::class, OrdinaryDeferredReceiptProvider::class]);

it('restores the resolving context when a deferred callback throws', function (): void {
    $receipts = resolve(ExtensionContributionReceiptRegistry::class);
    $provider = new OrdinaryDeferredReceiptProvider(app());
    $owner = ExtensionContributionReceiptContext::forPackage('test/throwing-owner', 'runtime', $provider::class);
    $consumer = ExtensionContributionReceiptContext::forPackage('test/throwing-consumer', 'runtime', DeferredReceiptRegistry::class);
    app()->bind('test.throwing-receipts', DeferredReceiptRegistry::class);
    $receipts->withContexts([$owner], fn () => $provider->deferReceipt('test.throwing-receipts', 'before-failure', static fn () => throw new RuntimeException('deferred failure')));

    $receipts->withContexts([$consumer], function () use ($receipts, $consumer): void {
        expect(function (): void {
            resolve('test.throwing-receipts');
        })->toThrow(RuntimeException::class, 'deferred failure');
        expect($receipts->activeContexts())->toBe([$consumer]);
    });

    expect(array_column($receipts->forPackage('test/throwing-owner'), 'key'))->toBe(['before-failure'])
        ->and($receipts->forPackage('test/throwing-consumer'))->toBe([])
        ->and($receipts->activeContexts())->toBe([]);
});

it('preserves immediate callbacks and does not borrow a later context for context-free declarations', function (): void {
    $receipts = resolve(ExtensionContributionReceiptRegistry::class);
    $provider = new OrdinaryDeferredReceiptProvider(app());
    app()->singleton('test.immediate-receipts', DeferredReceiptRegistry::class);
    resolve('test.immediate-receipts');
    $owner = ExtensionContributionReceiptContext::forPackage('test/immediate-owner', 'runtime', $provider::class);
    $receipts->withContexts([$owner], fn () => $provider->deferReceipt('test.immediate-receipts', 'immediate'));
    expect(array_column($receipts->forPackage('test/immediate-owner'), 'key'))->toBe(['immediate']);

    app()->bind('test.context-free-receipts', DeferredReceiptRegistry::class);
    $provider->deferReceipt('test.context-free-receipts', 'context-free');
    $receipts->withContexts([$owner], fn () => resolve('test.context-free-receipts'));
    expect(array_column($receipts->forPackage('test/immediate-owner'), 'key'))->toBe(['immediate'])
        ->and($receipts->activeContexts())->toBe([]);
});
