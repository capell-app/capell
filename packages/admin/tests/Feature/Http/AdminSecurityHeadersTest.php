<?php

declare(strict_types=1);

use Capell\Core\Octane\Resettable;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Support\Livewire\Partials\DataStoreOverride;
use Illuminate\Container\Container;
use Livewire\Component;
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

it('keeps the filament livewire data store override within one operation', function (): void {
    $component = new class extends Component {};
    $operationSentinel = (object) [
        'user' => 'admin-1',
        'site' => 'site-1',
        'request' => 'request-1',
    ];
    $firstDataStore = resolve(DataStore::class);

    $firstDataStore->set($component, 'operation', $operationSentinel);

    expect($firstDataStore)
        ->toBeInstanceOf(DataStoreOverride::class)
        ->and(resolve(DataStore::class))->toBe($firstDataStore)
        ->and(resolve(DataStore::class)->get($component, 'operation'))->toBe($operationSentinel);

    Container::getInstance()->forgetScopedInstances();

    $secondDataStore = resolve(DataStore::class);

    expect($secondDataStore)
        ->toBeInstanceOf(DataStoreOverride::class)
        ->not->toBe($firstDataStore)
        ->and($secondDataStore->get($component, 'operation'))->toBeNull()
        ->and(collect(app()->tagged(Resettable::TAG))->contains($firstDataStore))->toBeFalse()
        ->and(collect(app()->tagged(Resettable::TAG))->contains($secondDataStore))->toBeFalse();
});
