<?php

declare(strict_types=1);

use Capell\Core\Support\Json\JsonEquivalence;

it('keeps identical raw JSON equivalent without decoding', function (string $json): void {
    expect(JsonEquivalence::equivalent($json, $json))->toBeTrue();
})->with([
    'leading zero string' => ['{"code":"001"}'],
    'quoted big integer' => ['{"value":"9223372036854775808"}'],
    'big integer token' => ['{"value":9223372036854775808}'],
    'invalid JSON' => ['{"value":'],
    'beyond decode depth' => [str_repeat('[', 513) . '0' . str_repeat(']', 513)],
]);

it('compares numeric tokens losslessly and distinguishes quoted strings', function (string $original, string $submitted, bool $equivalent): void {
    expect(json_validate($original))->toBeTrue()
        ->and(json_validate($submitted))->toBeTrue()
        ->and(JsonEquivalence::equivalent($original, $submitted))->toBe($equivalent)
        ->and(JsonEquivalence::equivalent($submitted, $original))->toBe($equivalent);
})->with([
    'quoted leading zero with whitespace' => ['{"code":"001"}', '{ "code": "001" }', true],
    'quoted big integer with whitespace' => ['{"value":"9223372036854775808"}', '{ "value": "9223372036854775808" }', true],
    'reordered big integer token' => ['{"a":1,"value":9223372036854775808}', '{"value":9223372036854775808,"a":1}', true],
    'rounding collision' => ['{"value":0.1}', '{"value":0.10000000000000001}', false],
    'underflow' => ['{"value":0.0}', '{"value":1e-400}', false],
    'overflow collision' => ['1e400', '2e400', false],
    'exact exponent' => ['1.0', '1e0', true],
    'fractional exponent' => ['0.0100', '1e-2', true],
    'negative exponent' => ['-10.50', '-105e-1', true],
    'leading zero exponent' => ['1.0', '1e+0000', true],
    'different scalar types' => ['1', '1.0', false],
    // Zero has the same exact numeric value regardless of its sign.
    'signed integer zero' => ['-0', '0', true],
    'signed decimal zero' => ['-0.0', '0e0', true],
    'numeric marker in a string' => ['"integer:1"', '1', false],
    'escaped quote before number' => ['{"x":"a\"0.1","n":0.1}', '{"n":0.10000000000000001,"x":"a\"0.1"}', false],
    'duplicate keys retain the last number' => ['{"x":0.1,"x":0.2}', '{"x":0.2}', true],
    'huge exponent remains uncertain' => ['1e99999999999999999999', '10e99999999999999999998', false],
    'huge identical token with whitespace' => ['1e99999999999999999999', ' 1e99999999999999999999 ', true],
]);

it('compares JSON while preserving identity and scalar types', function (string $original, string $submitted, bool $equivalent): void {
    expect(JsonEquivalence::equivalent($original, $submitted))->toBe($equivalent)
        ->and(JsonEquivalence::equivalent($submitted, $original))->toBe($equivalent);
})->with([
    'reordered spaced object' => ['{"slug": "x", "label": null}', '{"label":null,"slug":"x"}', true],
    'nested objects in lists' => ['[{"b":2,"a":{"y":null,"x":1}}]', '[{"a":{"x":1,"y":null},"b":2}]', true],
    'numeric object keys' => ['{"1":"b","0":"a"}', '{"0":"a","1":"b"}', true],
    'empty objects' => ['{}', ' { } ', true],
    'empty lists' => ['[]', ' [ ] ', true],
    'same null' => ['null', ' null ', true],
    'same boolean' => ['true', ' true ', true],
    'same integer' => ['1', ' 1 ', true],
    'same float' => ['1.0', '1e0', true],
    'escaped string' => ['"a"', '"\u0061"', true],
    'empty object and list' => ['{}', '[]', false],
    'numeric object and list' => ['{"0":"a","1":"b"}', '["a","b"]', false],
    'nested object and list' => ['{"settings":{}}', '{"settings":[]}', false],
    'reordered list' => ['[1,2]', '[2,1]', false],
    'different list size' => ['[1]', '[1,2]', false],
    'different object keys' => ['{"a":null}', '{"b":null}', false],
    'missing null property' => ['{"a":null}', '{}', false],
    'nested edit' => ['{"a":{"b":1}}', '{"a":{"b":2}}', false],
    'integer and float' => ['1', '1.0', false],
    'integer and string' => ['1', '"1"', false],
    'integer and boolean' => ['1', 'true', false],
    'null and boolean' => ['null', 'false', false],
    'list and scalar' => ['[]', 'null', false],
    'object and scalar' => ['{}', 'null', false],
    'changed big integer' => ['9223372036854775808', '9223372036854775809', false],
    'big integer and string' => ['9223372036854775808', '"9223372036854775808"', false],
    'identical big integer' => ['9223372036854775808', '9223372036854775808', true],
    'identical numeric strings' => ['"9223372036854775808"', '"9223372036854775808"', true],
    'negative big integer' => ['-9223372036854775809', '"-9223372036854775809"', false],
    'nested big integer' => ['{"value":9223372036854775808}', '{"value":"9223372036854775808"}', false],
    'identical overflow token' => ['1e400', '1e400', true],
    'invalid original' => ['{"value":', '{"value":1}', false],
    'both invalid' => ['not JSON', 'not JSON', true],
    'empty input' => ['', '', true],
]);
