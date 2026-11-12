<?php

declare(strict_types=1);

use Capell\Core\Support\Url\RedirectContext;
use Capell\Core\Support\Url\RedirectTargetPolicy;
use Capell\Core\Support\Url\RedirectTargetRejection;
use Capell\Core\Support\Url\RedirectTargetRule;
use Illuminate\Support\Facades\Validator;

it('accepts same-origin paths and web addresses exactly as given', function (string $target): void {
    $result = (new RedirectTargetPolicy)->validate($target);

    expect($result->allowed())->toBeTrue()
        ->and($result->target)->toBe($target)
        ->and($result->rejection)->toBeNull();
})->with([
    'path' => '/about',
    'path keeps case, query and fragment' => '/Pricing/Plans?Ref=Footer&utm_source=Mail#Annual',
    'backslash after the first segment' => '/downloads/report\final.pdf',
    'https address' => 'https://example.com/Path?Q=1#Top',
    'upper-case scheme' => 'HTTPS://Example.com/path',
    'address with port' => 'https://example.com:8443/path',
    'IPv6 address' => 'https://[2606:2800:220:1:248:1893:25c8:1946]/path',
]);

it('refuses destinations that leave the site or hide another host', function (string $target, RedirectTargetRejection $rejection): void {
    $result = (new RedirectTargetPolicy)->validate($target);

    expect($result->allowed())->toBeFalse()
        ->and($result->target)->toBeNull()
        ->and($result->rejection)->toBe($rejection);
})->with([
    'empty' => ['', RedirectTargetRejection::Empty],
    'spaces only' => ['   ', RedirectTargetRejection::Empty],
    'leading space' => [' //evil.example', RedirectTargetRejection::Malformed],
    'protocol relative' => ['//evil.example/path', RedirectTargetRejection::ProtocolRelative],
    'triple slash' => ['///evil.example/path', RedirectTargetRejection::ProtocolRelative],
    'slash backslash' => ['/\evil.example', RedirectTargetRejection::ProtocolRelative],
    'backslash slash' => ['\/evil.example', RedirectTargetRejection::ProtocolRelative],
    'double backslash' => ['\\\\evil.example', RedirectTargetRejection::ProtocolRelative],
    'single backslash' => ['\evil.example', RedirectTargetRejection::ProtocolRelative],
    'tab between slashes' => ["/\t/evil.example", RedirectTargetRejection::ControlCharacter],
    'newline between slashes' => ["/\n/evil.example", RedirectTargetRejection::ControlCharacter],
    'leading tab' => ["\t//evil.example", RedirectTargetRejection::ControlCharacter],
    'null byte' => ["/about\0", RedirectTargetRejection::ControlCharacter],
    'tab inside scheme' => ["java\tscript:alert(1)", RedirectTargetRejection::ControlCharacter],
    'javascript scheme' => ['javascript:alert(1)', RedirectTargetRejection::Scheme],
    'data scheme' => ['data:text/html,<script>alert(1)</script>', RedirectTargetRejection::Scheme],
    'ftp scheme' => ['ftp://example.com/file', RedirectTargetRejection::Scheme],
    'entity-encoded scheme' => ['&#106;avascript:alert(1)', RedirectTargetRejection::Malformed],
    'scheme without authority' => ['https:evil.example', RedirectTargetRejection::Malformed],
    'empty authority' => ['https:///evil.example', RedirectTargetRejection::Malformed],
    'backslash ends the authority in browsers' => ['https://example.com\@evil.example', RedirectTargetRejection::Malformed],
    'space in the host' => ['https://exa mple.com/path', RedirectTargetRejection::Malformed],
    'credentials hide the real host' => ['https://example.com@evil.example/path', RedirectTargetRejection::Credentials],
    'user and password' => ['https://user:secret@example.com/', RedirectTargetRejection::Credentials],
]);

it('requires an allowed host when the context names the site domains', function (string $target, bool $allowed): void {
    $result = (new RedirectTargetPolicy)->validate($target, RedirectContext::sameHost(['Example.com', 'www.example.com']));

    expect($result->allowed())->toBe($allowed)
        ->and($result->rejection)->toBe($allowed ? null : RedirectTargetRejection::Host);
})->with([
    'listed host' => ['https://example.com/path', true],
    'listed host in another case' => ['https://WWW.Example.com/path', true],
    'listed host with a trailing dot' => ['https://example.com./path', true],
    'unlisted host' => ['https://evil.example/path', false],
    'listed host as a prefix of another' => ['https://example.com.evil.example/path', false],
]);

it('refuses relative paths when the context requires a full address', function (): void {
    $result = (new RedirectTargetPolicy)->validate('/about', new RedirectContext(allowRelative: false));

    expect($result->rejection)->toBe(RedirectTargetRejection::RelativeNotAllowed);
});

it('limits schemes to those the context allows', function (): void {
    $policy = new RedirectTargetPolicy;

    expect($policy->validate('http://example.com/', new RedirectContext(schemes: ['https']))->rejection)
        ->toBe(RedirectTargetRejection::Scheme)
        ->and($policy->validate('https://example.com/', new RedirectContext(schemes: ['HTTPS']))->allowed())
        ->toBeTrue();
});

it('explains each refusal through the validation rule', function (mixed $value, Closure $message): void {
    $validator = Validator::make(['destination' => $value], ['destination' => [new RedirectTargetRule]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('destination'))->toBe($message())
        ->and($message())->not->toStartWith('capell-core::');
})->with([
    'not a string' => [['/about'], fn (): string => __('capell-core::message.redirect_target_invalid')],
    'protocol relative' => ['//evil.example', fn (): string => __('capell-core::message.redirect_target_protocol_relative')],
    'control character' => ["/\t/evil.example", fn (): string => __('capell-core::message.redirect_target_control_character')],
    'scheme' => ['javascript:alert(1)', fn (): string => __('capell-core::message.redirect_target_scheme')],
    'malformed' => ['https:///evil.example', fn (): string => __('capell-core::message.redirect_target_malformed')],
    'credentials' => ['https://user@evil.example', fn (): string => __('capell-core::message.redirect_target_credentials')],
]);

it('explains a required full address through the validation rule', function (): void {
    $validator = Validator::make(['destination' => '/about'], ['destination' => [new RedirectTargetRule(new RedirectContext(allowRelative: false))]]);

    expect($validator->errors()->first('destination'))->toBe(__('capell-core::message.redirect_target_relative_not_allowed'));
});

it('passes allowed destinations through the validation rule', function (): void {
    $validator = Validator::make(
        ['destination' => 'https://example.com/thanks'],
        ['destination' => [new RedirectTargetRule(RedirectContext::sameHost(['example.com']))]],
    );

    expect($validator->passes())->toBeTrue();

    $offSite = Validator::make(
        ['destination' => 'https://evil.example/thanks'],
        ['destination' => [new RedirectTargetRule(RedirectContext::sameHost(['example.com']))]],
    );

    expect($offSite->errors()->first('destination'))->toBe(__('capell-core::message.redirect_target_host'));
});
