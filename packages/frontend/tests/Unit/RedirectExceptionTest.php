<?php

declare(strict_types=1);

use Capell\Frontend\Exceptions\RedirectException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\ViewErrorBag;

it('creates a RedirectException with correct headers and errors', function (): void {
    $response = redirect('/target')->with('error', 'Something went wrong')->withErrors(['foo' => 'bar']);
    $exception = new RedirectException($response);

    $laravelResponse = $exception->toResponse(request());

    expect($exception->redirectUrl)->toBe('http://localhost/target')
        ->and($exception->error)->toBe('Something went wrong')
        ->and($exception->errors)->toBeInstanceOf(ViewErrorBag::class);

    // The response should be a RedirectResponse
    expect($laravelResponse)->toBeInstanceOf(RedirectResponse::class);
    assert($laravelResponse instanceof RedirectResponse);
    expect($laravelResponse->getTargetUrl())->toBe('http://localhost/target');

    // Check session data directly on the response
    $session = expectPresent($laravelResponse->getSession());
    expect($session->get('error'))->toBe('Something went wrong');
    $errors = $session->get('errors');
    expect($errors)->toBeInstanceOf(ViewErrorBag::class);
    expect($errors->first('foo'))->toBe('bar');

    // The response should have the correct Location header
    expect($laravelResponse->headers->get('Location'))->toBe('http://localhost/target');
});
