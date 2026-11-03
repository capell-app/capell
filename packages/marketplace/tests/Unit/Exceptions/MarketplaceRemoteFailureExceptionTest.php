<?php

declare(strict_types=1);

use Capell\Marketplace\Exceptions\MarketplaceInstallFlowException;
use Capell\Marketplace\Exceptions\MarketplaceRemoteFailureException;

it('stores a bounded code for remote failures and anything unexpected', function (): void {
    expect(MarketplaceRemoteFailureException::diagnostic(new MarketplaceRemoteFailureException(503)))->toBe('remote_failure:503')
        ->and(MarketplaceRemoteFailureException::diagnostic(new RuntimeException('Remote marketplace schema is missing contract_version.')))->toBe('operation_failed')
        ->and(MarketplaceRemoteFailureException::diagnostic(new TypeError('argument #1')))->toBe('operation_failed');
});

it('keeps the message of a flow failure Capell authored itself', function (): void {
    $failure = new MarketplaceInstallFlowException('Marketplace install flow is missing entitlement for [vendor/package].');

    expect(MarketplaceRemoteFailureException::diagnostic($failure))->toBe($failure->getMessage());
});
