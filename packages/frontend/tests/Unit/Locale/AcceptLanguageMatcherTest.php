<?php

declare(strict_types=1);

use Capell\Frontend\Support\Locale\AcceptLanguageMatcher;

uses()->group('frontend', 'locale');

beforeEach(function (): void {
    $this->matcher = new AcceptLanguageMatcher;
});

it('orders preferences by q-value and drops rejections', function (): void {
    expect($this->matcher->preferences('de;q=0.3,fr-CA,en;q=0.8,es;q=0'))
        ->toBe(['fr-ca', 'en', 'de']);
});

it('reports no preference for an absent or wildcard header', function (): void {
    expect($this->matcher->preferences(null))->toBe([]);
    expect($this->matcher->preferences(''))->toBe([]);
    expect($this->matcher->preferences('*'))->toBe([]);
    expect($this->matcher->preferences('*;q=0.5'))->toBe([]);
});

it('accepts a tag listed at any q-value', function (): void {
    expect($this->matcher->accepts('fr-FR,fr;q=0.9,en;q=0.1', 'en'))->toBeTrue();
    expect($this->matcher->accepts('fr-FR,fr;q=0.9', 'en'))->toBeFalse();
});

it('treats a shared base language as a match in both directions', function (): void {
    expect($this->matcher->sameBaseLanguage('en-US', 'en-GB'))->toBeTrue();
    expect($this->matcher->sameBaseLanguage('fr-CA', 'fr'))->toBeTrue();
    expect($this->matcher->sameBaseLanguage('fr', 'fr-FR'))->toBeTrue();
    expect($this->matcher->sameBaseLanguage('pt-BR', 'es'))->toBeFalse();
});

it('picks the visitor’s highest-priority available language', function (): void {
    $available = [10 => 'en-gb', 20 => 'fr-fr', 30 => 'de-de'];

    expect($this->matcher->bestMatch('de;q=0.4,fr-CA;q=0.9', $available))->toBe(20);
    expect($this->matcher->bestMatch('nl,de', $available))->toBe(30);
    expect($this->matcher->bestMatch('ja', $available))->toBeNull();
});

it('normalises underscores and casing', function (): void {
    expect($this->matcher->normalise('PT_br'))->toBe('pt-br');
});
