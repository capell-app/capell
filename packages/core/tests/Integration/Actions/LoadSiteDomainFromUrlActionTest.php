<?php

declare(strict_types=1);

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;

beforeEach(function (): void {
    SiteDomain::factory()->createOne([
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => null,
        'status' => true,
    ]);
});

it('parses host from URL', function (): void {
    $result = LoadSiteDomainFromUrlAction::run('https://example.com/path');

    $domain = is_array($result) && isset($result[0])
        ? (is_object($result[0]) ? ($result[0]->domain ?? null) : ($result[0]['domain'] ?? null))
        : null;

    expect($domain)->toBe('example.com');
});

it('returns null on malformed URL', function (): void {
    $result = LoadSiteDomainFromUrlAction::run('not-a-url');

    expect($result)->toBeNull();
});
