<?php

declare(strict_types=1);

use Capell\Core\Support\Http\PublicEndpointThrottle;
use Capell\Core\Testing\Assertions\AssertsDeclaredThrottles;
use Illuminate\Support\Facades\Route;

/** @param  array<string, mixed>  $security */
function throttleManifest(array $security): string
{
    $path = tempnam(sys_get_temp_dir(), 'capell-throttle-') . '.json';
    file_put_contents($path, json_encode(['security' => $security], JSON_THROW_ON_ERROR));

    return $path;
}

/** @return array<string, mixed> */
function declaredSubmitThrottle(array $routes = ['fixture.submit']): array
{
    return [
        'publicSurface' => ['routeNames' => ['fixture.submit'], 'webhookRoutes' => []],
        'throttles' => ['capell-fixture-submit' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => $routes]],
    ];
}

beforeEach(function (): void {
    PublicEndpointThrottle::for('capell-fixture-submit')->allow(5)->perSeconds(60)->register();
    Route::post('fixture/submit', fn (): string => 'ok')->name('fixture.submit')->middleware('throttle:capell-fixture-submit');
    Route::post('fixture/open', fn (): string => 'ok')->name('fixture.open');
    Route::post('fixture/other', fn (): string => 'ok')->name('fixture.other')->middleware('throttle:capell-fixture-other');
    Route::post('fixture/hook', fn (): string => 'ok')->name('fixture.hook');
    Route::getRoutes()->refreshNameLookups();
});

it('passes when every declared purpose and public route agrees with the registered routes', function (): void {
    AssertsDeclaredThrottles::run(throttleManifest(declaredSubmitThrottle()));

    expect(true)->toBeTrue();
});

it('reads routes in the generated METHOD /uri (name) manifest form, named or not', function (): void {
    Route::post('fixture/unnamed', fn (): string => 'ok')->middleware('throttle:capell-fixture-submit');
    Route::getRoutes()->refreshNameLookups();

    $routes = ['POST /fixture/submit (fixture.submit)', 'POST /fixture/unnamed'];

    AssertsDeclaredThrottles::run(throttleManifest([
        'publicSurface' => ['routeNames' => $routes, 'webhookRoutes' => []],
        'throttles' => ['capell-fixture-submit' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => $routes]],
    ]));

    expect(fn () => AssertsDeclaredThrottles::run(throttleManifest([
        'publicSurface' => ['routeNames' => ['POST /fixture/open (fixture.open)']],
        'throttles' => ['capell-fixture-submit' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => $routes]],
    ])))->toThrow(AssertionError::class, 'public route [POST /fixture/open (fixture.open)] is not guarded');
});

it('skips packages that declare no throttles', function (): void {
    AssertsDeclaredThrottles::run(throttleManifest(['publicSurface' => ['routeNames' => ['fixture.open']]]));

    expect(true)->toBeTrue();
});

it('exempts webhook routes, which the webhook macro throttles', function (): void {
    $security = declaredSubmitThrottle();
    $security['publicSurface'] = ['routeNames' => ['fixture.submit', 'fixture.hook'], 'webhookRoutes' => ['fixture.hook']];

    AssertsDeclaredThrottles::run(throttleManifest($security));

    expect(true)->toBeTrue();
});

it('fails with the contract ID when the declaration and the registered routes disagree', function (array $security, string $message): void {
    expect(fn () => AssertsDeclaredThrottles::run(throttleManifest($security)))
        ->toThrow(AssertionError::class, $message);
})->with([
    'no limiter registered' => [
        ['throttles' => ['capell-fixture-missing' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => []]]],
        'no rate limiter is registered',
    ],
    'limit differs from declaration' => [
        ['throttles' => ['capell-fixture-submit' => ['maxAttempts' => 10, 'decaySeconds' => 60, 'routes' => ['fixture.submit']]]],
        'declares 10 attempts per 60 seconds but registers 5 per 60',
    ],
    'declared route not registered' => [declaredSubmitThrottle(['fixture.gone']), 'declares route [fixture.gone], which is not registered'],
    'declared route not throttled by the purpose' => [declaredSubmitThrottle(['fixture.open']), 'does not run throttle:capell-fixture-submit'],
    'public route without a throttle' => [
        array_replace(declaredSubmitThrottle(), ['publicSurface' => ['routeNames' => ['fixture.submit', 'fixture.open']]]),
        'public route [fixture.open] is not guarded',
    ],
    'public route with an undeclared purpose' => [
        array_replace(declaredSubmitThrottle(), ['publicSurface' => ['routeNames' => ['fixture.submit', 'fixture.other']]]),
        'runs throttle:capell-fixture-other, which security.throttles does not declare',
    ],
]);

it('names the contract and manifest in every failure', function (): void {
    $path = throttleManifest(declaredSubmitThrottle(['fixture.gone']));

    expect(fn () => AssertsDeclaredThrottles::run($path))->toThrow(AssertionError::class, '[security.throttles] ' . $path);
});
