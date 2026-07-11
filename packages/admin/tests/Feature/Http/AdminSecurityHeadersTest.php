<?php

declare(strict_types=1);

use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Support\Livewire\Partials\DataStoreOverride;
use Livewire\Mechanisms\DataStore;

uses(CreatesAdminUser::class);

it('adds hardening headers to admin responses', function (): void {
    $this->get('/admin/login')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('can disable admin hardening headers for applications that own headers at the edge', function (): void {
    config()->set('capell-admin.security_headers.enabled', false);

    $this->get('/admin/login')
        ->assertOk()
        ->assertHeaderMissing('X-Content-Type-Options')
        ->assertHeaderMissing('Referrer-Policy')
        ->assertHeaderMissing('Permissions-Policy')
        ->assertHeaderMissing('X-Frame-Options');
});

it('keeps the filament livewire data store override stateful', function (): void {
    $firstDataStore = resolve(DataStore::class);
    $secondDataStore = resolve(DataStore::class);

    expect($firstDataStore)
        ->toBeInstanceOf(DataStoreOverride::class)
        ->and($secondDataStore)->toBe($firstDataStore);
});
