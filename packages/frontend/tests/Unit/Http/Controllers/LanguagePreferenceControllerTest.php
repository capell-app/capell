<?php

declare(strict_types=1);

use Capell\Frontend\Http\Controllers\LanguagePreferenceController;
use Capell\Frontend\Support\Locale\AcceptLanguageMatcher;
use Capell\Frontend\Support\Locale\VisitorLanguageCookie;
use Illuminate\Http\Request;

it('records a valid explicit language and redirects to the request origin', function (): void {
    $request = Request::create('https://example.test/preferences', 'GET', ['language' => 'PT_br']);

    $response = (new LanguagePreferenceController(
        new VisitorLanguageCookie,
        new AcceptLanguageMatcher,
    ))($request);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->getTargetUrl())->toBe('https://example.test/')
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->getCookies()[0]->getName())->toBe(VisitorLanguageCookie::NAME)
        ->and($response->headers->getCookies()[0]->getValue())->toBe('pt-br');
});

it('falls back safely when the target is absent or the language is invalid', function (): void {
    $request = Request::create('https://example.test/preferences', 'GET', ['to' => 'https://other.test/path', 'language' => '***']);

    $response = (new LanguagePreferenceController(
        new VisitorLanguageCookie,
        new AcceptLanguageMatcher,
    ))($request);

    expect($response->getTargetUrl())->toBe('https://example.test/')
        ->and($response->headers->getCookies())->toBe([]);
});
