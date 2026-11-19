<?php

declare(strict_types=1);

use Capell\Tests\Support\LegacyAdapter;
use Illuminate\Http\Request;

// Published Access Gate releases ship conditions that implement the deprecated rule condition
// contract. Deleting the contract or its context data makes those classes fatal when loaded.
// Class names are strings so the deprecations gate keeps guarding production code (see
// tests/Support/LegacyAdapter.php). Remove with the contract in 2.0.
it('still loads a condition shaped like the ones published extension releases ship', function (): void {
    require_once __DIR__ . '/Fixtures/PublishedRuleCondition.php.fixture';

    $conditionClass = 'Capell\\Frontend\\Tests\\Unit\\Legacy\\Fixtures\\PublishedRuleCondition';
    $context = LegacyAdapter::invoke($conditionClass, 'contextFor', Request::create('https://example.test/members'));

    expect($context)->toBeInstanceOf('Capell\\Frontend\\Data\\FrontendRuleContextData')
        ->and(class_implements($conditionClass))->toContain('Capell\\Frontend\\Contracts\\FrontendRuleCondition')
        ->and(LegacyAdapter::invoke($conditionClass, 'evaluate', ['host' => 'example.test'], $context))->toBeTrue()
        ->and(LegacyAdapter::invoke($conditionClass, 'evaluate', ['host' => 'other.test'], $context))->toBeFalse();
});

// Published releases register their conditions only when this registry class exists. Restoring it
// would quietly switch that registration back on for every installed release.
it('keeps published rule conditions unregistered by leaving the registry removed', function (): void {
    expect(class_exists('Capell\\Frontend\\Support\\Rules\\FrontendRuleConditionRegistry'))->toBeFalse();
});
