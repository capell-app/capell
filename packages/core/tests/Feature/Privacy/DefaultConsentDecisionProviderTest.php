<?php

declare(strict_types=1);

use Capell\Core\Contracts\Privacy\ConsentDecisionProvider;
use Capell\Core\Enums\ConsentDecision;
use Capell\Core\Support\Privacy\DefaultConsentDecisionProvider;
use Illuminate\Http\Request;

function consentRequest(array $headers = []): Request
{
    $request = Request::create('https://example.test/about');

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

it('binds the default provider when no consent manager has bound one', function (): void {
    expect(resolve(ConsentDecisionProvider::class))->toBeInstanceOf(DefaultConsentDecisionProvider::class)
        ->and(resolve(ConsentDecisionProvider::class)->categories())->toBe(['essential', 'functional', 'preferences', 'analytics', 'marketing']);
});

it('resolves each category to its configured default and always grants essential', function (): void {
    config(['capell.privacy.consent.defaults' => [
        'essential' => 'denied',
        'functional' => 'granted',
        'preferences' => 'denied',
        'analytics' => 'undecided',
        'marketing' => 'granted',
    ]]);
    $provider = resolve(ConsentDecisionProvider::class);

    expect($provider->decide('essential', consentRequest()))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('functional', consentRequest()))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('preferences', consentRequest()))->toBe(ConsentDecision::Denied)
        ->and($provider->decide('analytics', consentRequest()))->toBe(ConsentDecision::Undecided)
        ->and($provider->decide('marketing', consentRequest()))->toBe(ConsentDecision::Granted);
});

it('refuses the opt-out categories for a Global Privacy Control or Do Not Track request', function (array $headers): void {
    config(['capell.privacy.consent.defaults' => ['functional' => 'granted', 'analytics' => 'granted', 'marketing' => 'granted']]);
    $provider = resolve(ConsentDecisionProvider::class);

    expect($provider->decide('analytics', consentRequest($headers)))->toBe(ConsentDecision::Denied)
        ->and($provider->decide('marketing', consentRequest($headers)))->toBe(ConsentDecision::Denied)
        ->and($provider->decide('functional', consentRequest($headers)))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('essential', consentRequest($headers)))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('analytics', consentRequest()))->toBe(ConsentDecision::Granted);
})->with([
    'Sec-GPC' => [['Sec-GPC' => '1']],
    'DNT' => [['DNT' => '1']],
]);

it('ignores opt-out header values other than 1', function (): void {
    config(['capell.privacy.consent.defaults.analytics' => 'granted']);

    expect(resolve(ConsentDecisionProvider::class)->decide('analytics', consentRequest(['Sec-GPC' => '0', 'DNT' => 'unspecified'])))
        ->toBe(ConsentDecision::Granted);
});

it('decides a named purpose through its category and leaves an unknown purpose undecided', function (): void {
    config([
        'capell.privacy.consent.defaults' => ['functional' => 'granted', 'marketing' => 'granted'],
        'capell.privacy.consent.purposes' => ['youtube' => 'marketing', 'openstreetmap' => 'functional'],
    ]);
    $provider = resolve(ConsentDecisionProvider::class);

    expect($provider->purposes())->toBe(['youtube' => 'marketing', 'openstreetmap' => 'functional'])
        ->and($provider->decide('youtube', consentRequest()))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('youtube', consentRequest(['Sec-GPC' => '1'])))->toBe(ConsentDecision::Denied)
        ->and($provider->decide('openstreetmap', consentRequest(['Sec-GPC' => '1'])))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('vimeo', consentRequest()))->toBe(ConsentDecision::Undecided);
});

it('fails closed on malformed configuration', function (): void {
    config([
        'capell.privacy.consent.defaults' => ['analytics' => 'yes', 'marketing' => true],
        'capell.privacy.consent.opt_out_signal_categories' => ['essential', 'unknown', 'functional'],
        'capell.privacy.consent.purposes' => ['youtube' => 'ads', 'marketing' => 'functional', '' => 'marketing', 'maps' => 'functional'],
    ]);
    $provider = resolve(ConsentDecisionProvider::class);

    expect($provider->decide('analytics', consentRequest()))->toBe(ConsentDecision::Undecided)
        ->and($provider->decide('marketing', consentRequest()))->toBe(ConsentDecision::Undecided)
        ->and($provider->decide('essential', consentRequest(['Sec-GPC' => '1'])))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('functional', consentRequest(['Sec-GPC' => '1'])))->toBe(ConsentDecision::Denied)
        // A purpose cannot reassign a category name, and an invalid category drops the purpose.
        ->and($provider->purposes())->toBe(['maps' => 'functional']);
});

it('asks before every optional category by default', function (): void {
    $provider = resolve(ConsentDecisionProvider::class);

    expect($provider->decide('essential', consentRequest()))->toBe(ConsentDecision::Granted)
        ->and($provider->decide('functional', consentRequest()))->toBe(ConsentDecision::Undecided)
        ->and($provider->decide('preferences', consentRequest()))->toBe(ConsentDecision::Undecided)
        ->and($provider->decide('analytics', consentRequest()))->toBe(ConsentDecision::Undecided)
        ->and($provider->decide('marketing', consentRequest()))->toBe(ConsentDecision::Undecided);
});
