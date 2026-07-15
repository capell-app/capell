<?php

declare(strict_types=1);

use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Enums\MarketplaceInstallSource;

it('normalizes typed install requests for every entry source', function (MarketplaceInstallSource $source): void {
    $request = MarketplaceInstallRequestData::make(
        extensionSlug: ' Forms ',
        options: ['site' => 2, 'mode' => 'safe'],
        actor: MarketplaceInstallActorData::system('test-' . $source->value),
        betaAcknowledged: true,
        source: $source,
    );

    expect($request->extensionSlug)->toBe('forms')
        ->and(array_keys($request->options))->toBe(['mode', 'site'])
        ->and($request->betaAcknowledged)->toBeTrue()
        ->and($request->source)->toBe($source)
        ->and($request->actor->identifier)->toBe('test-' . $source->value);
})->with(MarketplaceInstallSource::cases());

it('rejects an empty extension selection', function (): void {
    MarketplaceInstallRequestData::make(
        extensionSlug: ' ',
        options: [],
        actor: MarketplaceInstallActorData::system(),
        betaAcknowledged: false,
        source: MarketplaceInstallSource::Programmatic,
    );
})->throws(InvalidArgumentException::class);
