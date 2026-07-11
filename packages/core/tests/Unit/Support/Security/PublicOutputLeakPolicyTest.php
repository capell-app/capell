<?php

declare(strict_types=1);

use Capell\Core\Support\Security\PublicHtmlSanitizer;
use Capell\Core\Support\Security\PublicOutputLeakPolicy;

it('drives sanitizer key filtering from the core leak policy', function (): void {
    $policy = new PublicOutputLeakPolicy;
    $payload = array_fill_keys($policy->blockedPublicKeys(), 'private');
    $payload['public'] = 'safe';

    expect((new PublicHtmlSanitizer($policy))->sanitizePublicValue($payload))
        ->toBe(['public' => 'safe']);
});
