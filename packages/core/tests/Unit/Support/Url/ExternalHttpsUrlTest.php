<?php

declare(strict_types=1);

use Capell\Core\Support\Url\ExternalHttpsUrl;

it('accepts only an absolute https URL with a host and no credentials', function (mixed $value, ?string $expected): void {
    expect(ExternalHttpsUrl::sanitise($value))->toBe($expected);
})->with([
    'https' => ['https://docs.example/guide?a=1#top', 'https://docs.example/guide?a=1#top'],
    'padded https' => ['  https://docs.example/guide ', 'https://docs.example/guide'],
    'script' => ['javascript:alert(1)', null],
    'mixed case script' => ['JaVaScRiPt:alert(1)', null],
    'data' => ['data:text/html,x', null],
    'http' => ['http://docs.example', null],
    'scheme relative' => ['//docs.example', null],
    'relative path' => ['/guide', null],
    'credentials' => ['https://user:pass@docs.example', null],
    'tab hidden in scheme' => ["java\tscript:alert(1)", null],
    'backslash authority' => ['https://docs.example\\@evil.example', null],
    'no host' => ['https://', null],
    'empty' => ['', null],
    'not a string' => [['https://docs.example'], null],
]);
