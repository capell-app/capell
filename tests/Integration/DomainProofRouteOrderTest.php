<?php

declare(strict_types=1);

use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Capell\Marketplace\Support\DomainProof\DomainProofChallengeStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('serves the domain proof route ahead of the frontend page catch-all', function (): void {
    $this->app->register(MarketplaceServiceProvider::class);
    Route::getRoutes()->refreshNameLookups();
    $token = Str::random(64);

    $proofRoute = Route::getRoutes()->match(Request::create('/.well-known/capell-domain-proof/' . $token));
    $pageRoute = Route::getRoutes()->match(Request::create('/about-us'));

    // The catch-all is really registered, so the match above proves precedence over it.
    expect($pageRoute->getName())->toBe('capell-frontend.page')
        ->and($proofRoute->getName())->toBe('capell-marketplace.domain-proof');

    resolve(DomainProofChallengeStore::class)->rememberFromResponse([
        'token' => $token,
        'host' => 'localhost',
        'expires_at' => now()->addMinutes(5)->toIso8601String(),
    ]);

    expect(trim((string) $this->get('/.well-known/capell-domain-proof/' . $token)->assertOk()->getContent()))->toBe($token);
});
