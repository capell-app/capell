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
