<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Illuminate\Http\Request;

// Published Access Gate releases ship conditions shaped like this one. Deleting the deprecated
// contract or its context data makes those classes fatal when anything loads them.
it('still loads a condition shaped like the ones published extension releases ship', function (): void {
    $condition = new class implements FrontendRuleCondition
    {
        public function key(): string
        {
            return 'published_extension_condition';
        }

        public function evaluate(array $parameters, FrontendRuleContextData $context): bool
        {
            return $context->request->getHost() === ($parameters['host'] ?? null);
        }
    };

    $context = new FrontendRuleContextData(Request::create('https://example.test/members'));

    expect($condition->evaluate(['host' => 'example.test'], $context))->toBeTrue()
        ->and($condition->evaluate(['host' => 'other.test'], $context))->toBeFalse();
});

// Published releases register their conditions only when this registry class exists. Restoring it
// would quietly switch that registration back on for every installed release.
it('keeps published rule conditions unregistered by leaving the registry removed', function (): void {
    $registry = implode('\\', ['Capell', 'Frontend', 'Support', 'Rules', 'FrontendRuleConditionRegistry']);

    expect(class_exists($registry))->toBeFalse();
});
