<?php

declare(strict_types=1);

use Capell\Core\Support\Security\PublicHtmlSanitizer;
use Capell\Core\Support\Security\PublicOutputLeakPolicy;

it('drives sanitizer key filtering from the core leak policy', function (): void {
    $policy = new PublicOutputLeakPolicy;
    $payload = array_fill_keys($policy->blockedPublicKeys(), 'private');
    $payload['public'] = 'safe';

    expect(new PublicHtmlSanitizer($policy)->sanitizePublicValue($payload))
        ->toBe(['public' => 'safe']);
});

it('constrains the countdown runtime hook to an empty value', function (): void {
    $policy = new PublicOutputLeakPolicy;

    expect($policy->allowedCapellRuntimeAttributeValues()['data-capell-countdown'])->toBe(['']);
});

it('allows only the exact countdown name without a suffix family', function (): void {
    $policy = new PublicOutputLeakPolicy;

    expect($policy->allowedCapellRuntimeAttributes())->toContain('data-capell-countdown');

    foreach (['-model-id', '_model_id', ':model-id', '.model-id'] as $suffix) {
        $attribute = 'data-capell-countdown' . $suffix;

        expect($policy->allowedCapellRuntimeAttributes())->not->toContain($attribute);

        foreach ($policy->allowedCapellRuntimeAttributePrefixes() as $prefix) {
            expect(str_starts_with($attribute, $prefix))->toBeFalse();
        }
    }
});
