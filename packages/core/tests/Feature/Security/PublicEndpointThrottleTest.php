<?php

declare(strict_types=1);

use Capell\Core\Support\Http\PublicEndpointThrottle;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    RateLimiter::clear('capell-tests-submit');

    PublicEndpointThrottle::for('capell-tests-submit')->allow(2)->perSeconds(60)->register();

    Route::post('/throttle-test/{form}', static fn (): string => 'ok')
        ->middleware('throttle:capell-tests-submit')
        ->name('capell-tests.throttle.submit');
});

it('blocks a client once the declared limit is spent', function (): void {
    $this->post('/throttle-test/contact')->assertOk();
    $this->post('/throttle-test/contact')->assertOk();
    $this->post('/throttle-test/contact')->assertTooManyRequests();
});

it('does not let a client reset its bucket by changing the request body or query', function (): void {
    $this->post('/throttle-test/contact', ['email' => 'first@example.com'])->assertOk();
    $this->post('/throttle-test/contact?nonce=2', ['email' => 'second@example.com'])->assertOk();
    $this->post('/throttle-test/contact?nonce=3', ['email' => 'third@example.com'])->assertTooManyRequests();
});

it('keeps separate buckets for separate client addresses', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/throttle-test/contact')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/throttle-test/contact')->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->post('/throttle-test/contact')->assertOk();
});

it('adds named route parameters to the key only when asked', function (): void {
    $request = static function (string $form): Request {
        $request = Request::create('/throttle-test/' . $form, Symfony\Component\HttpFoundation\Request::METHOD_POST, server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->setRouteResolver(static fn () => Route::getRoutes()->match($request));

        return $request;
    };

    $shared = PublicEndpointThrottle::for('capell-tests-submit');
    $perForm = PublicEndpointThrottle::for('capell-tests-submit')->perRouteParameter('form');

    expect($shared->keyFor($request('contact')))->toBe($shared->keyFor($request('newsletter')))
        ->and($perForm->keyFor($request('contact')))->not->toBe($perForm->keyFor($request('newsletter')))
        ->and($perForm->keyFor($request('contact')))->toStartWith('capell-tests-submit:');
});

it('refuses purposes outside the capell-<package>-<purpose> convention and empty limits', function (Closure $build): void {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'no package prefix' => [static fn (): PublicEndpointThrottle => PublicEndpointThrottle::for('forms-submit')],
    'uppercase' => [static fn (): PublicEndpointThrottle => PublicEndpointThrottle::for('capell-Forms-submit')],
    'zero attempts' => [static fn (): PublicEndpointThrottle => PublicEndpointThrottle::for('capell-forms-submit')->allow(0)],
    'zero window' => [static fn (): PublicEndpointThrottle => PublicEndpointThrottle::for('capell-forms-submit')->perSeconds(0)],
]);

it('caps a client that invents route parameter values to mint fresh buckets', function (): void {
    RateLimiter::clear('capell-tests-per-form');
    PublicEndpointThrottle::for('capell-tests-per-form')->allow(1)->perSeconds(60)->perRouteParameter('form')->register();
    Route::post('/per-form-test/{form}', static fn (): string => 'ok')->middleware('throttle:capell-tests-per-form')->name('capell-tests.throttle.per-form');

    $this->post('/per-form-test/contact')->assertOk();
    $this->post('/per-form-test/contact')->assertTooManyRequests();

    foreach (range(2, PublicEndpointThrottle::CEILING_MULTIPLIER) as $invented) {
        $this->post('/per-form-test/invented-' . $invented)->assertOk();
    }

    $this->post('/per-form-test/one-more')->assertTooManyRequests();
});

it('gives signed-in actors on one address their own bucket unless the actor is ignored', function (bool $ignoringActor, bool $secondActorBlocked): void {
    RateLimiter::clear('capell-tests-actor');
    $throttle = PublicEndpointThrottle::for('capell-tests-actor')->allow(1)->perSeconds(60);
    ($ignoringActor ? $throttle->ignoringActor() : $throttle)->register();
    Route::post('/actor-test', static fn (): string => 'ok')->middleware('throttle:capell-tests-actor')->name('capell-tests.throttle.actor');

    $this->actingAs(User::factory()->create())->post('/actor-test')->assertOk();

    $response = $this->actingAs(User::factory()->create())->post('/actor-test');

    $secondActorBlocked ? $response->assertTooManyRequests() : $response->assertOk();
})->with([
    'keyed by actor' => [false, false],
    'ignoring the actor' => [true, true],
]);

it('spends in-action buckets per server-resolved subject and caps the client across subjects', function (): void {
    $throttle = PublicEndpointThrottle::for('capell-tests-thread')->allow(1)->perSeconds(60);
    $request = Request::create('/thread', Symfony\Component\HttpFoundation\Request::METHOD_POST, server: ['REMOTE_ADDR' => '203.0.113.30']);
    RateLimiter::clear($throttle->keyFor($request, withRouteParameters: false));

    expect($throttle->hit($request, ['thread' => 'post:1']))->toBeTrue()
        ->and($throttle->hit($request, ['thread' => 'post:1']))->toBeFalse()
        ->and($throttle->availableIn($request, ['thread' => 'post:1']))->toBeGreaterThan(0);

    foreach (range(2, PublicEndpointThrottle::CEILING_MULTIPLIER) as $thread) {
        expect($throttle->hit($request, ['thread' => 'post:' . $thread]))->toBeTrue();
    }

    expect($throttle->hit($request, ['thread' => 'post:99']))->toBeFalse()
        ->and($throttle->availableIn($request, ['thread' => 'post:99']))->toBeGreaterThan(0);
});

it('spends nothing when a request is refused', function (): void {
    $throttle = PublicEndpointThrottle::for('capell-tests-refused')->allow(1)->perSeconds(60);
    $request = Request::create('/refused', Symfony\Component\HttpFoundation\Request::METHOD_POST, server: ['REMOTE_ADDR' => '203.0.113.31']);
    $ceiling = $throttle->keyFor($request, withRouteParameters: false);
    RateLimiter::clear($ceiling);

    $throttle->hit($request, ['thread' => 'post:1']);
    $throttle->hit($request, ['thread' => 'post:1']);
    $throttle->hit($request, ['thread' => 'post:1']);

    expect(RateLimiter::attempts($ceiling))->toBe(1);
});

it('keeps an actor-only bucket when the client changes address, and clears it on request', function (): void {
    $user = User::factory()->create();
    $throttle = PublicEndpointThrottle::for('capell-tests-password')->allow(1)->perSeconds(60)->ignoringClientAddress();
    $from = static function (string $address) use ($user): Request {
        $request = Request::create('/password', Symfony\Component\HttpFoundation\Request::METHOD_POST, server: ['REMOTE_ADDR' => $address]);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
    $throttle->clear($from('203.0.113.40'));

    expect($throttle->hit($from('203.0.113.40')))->toBeTrue()
        ->and($throttle->hit($from('203.0.113.41')))->toBeFalse();

    $throttle->clear($from('203.0.113.41'));

    expect($throttle->hit($from('203.0.113.42')))->toBeTrue();
});

it('refuses a bucket keyed on neither the client address nor the actor', function (): void {
    $throttle = PublicEndpointThrottle::for('capell-tests-global')->ignoringActor()->ignoringClientAddress();

    expect(fn (): bool => $throttle->hit(Request::create('/global')))->toThrow(InvalidArgumentException::class);
});

it('refuses and gives back its attempts when an atomic increment crosses the limit after the check', function (): void {
    $throttle = PublicEndpointThrottle::for('capell-tests-race')->allow(1)->perSeconds(60);
    $request = Request::create('/race', Symfony\Component\HttpFoundation\Request::METHOD_POST, server: ['REMOTE_ADDR' => '203.0.113.60']);
    [$own, $ceiling] = $throttle->limitsFor($request, ['thread' => 'post:1']);

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->once()->with($own->key, 60)->andReturn(2);
    RateLimiter::shouldReceive('decrement')->once()->with($own->key, 60);
    RateLimiter::shouldReceive('hit')->never()->with($ceiling->key, 60);

    expect($throttle->hit($request, ['thread' => 'post:1']))->toBeFalse();
});
