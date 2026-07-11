<?php

declare(strict_types=1);

use Capell\Marketplace\Support\MarketplaceApprovalUrl;

beforeEach(function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api/v1']);
});

it('accepts approval urls on the configured marketplace origin', function (): void {
    $approvalUrl = 'https://marketplace.test/marketplace/connect/mcs_123';

    expect(MarketplaceApprovalUrl::validate($approvalUrl))->toBe($approvalUrl);
});

it('rejects approval urls outside the configured marketplace origin', function (string $approvalUrl): void {
    expect(fn (): string => MarketplaceApprovalUrl::validate($approvalUrl))
        ->toThrow(RuntimeException::class, 'Marketplace returned an invalid approval URL.');
})->with([
    'scheme downgrade' => ['http://marketplace.test/marketplace/connect/mcs_123'],
    'unexpected port' => ['https://marketplace.test:8443/marketplace/connect/mcs_123'],
    'unexpected host' => ['https://evil.test/marketplace/connect/mcs_123'],
    'userinfo' => ['https://capell@marketplace.test/marketplace/connect/mcs_123'],
    'javascript url' => ['javascript:alert(1)'],
]);
