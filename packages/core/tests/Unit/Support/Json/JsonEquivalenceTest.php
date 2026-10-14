<?php

declare(strict_types=1);

use Capell\Core\Support\Json\JsonEquivalence;

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
    'uncertain same big integer' => ['9223372036854775808', '9223372036854775808', false],
    'uncertain numeric strings' => ['"9223372036854775808"', '"9223372036854775808"', false],
    'negative big integer' => ['-9223372036854775809', '"-9223372036854775809"', false],
    'nested big integer' => ['{"value":9223372036854775808}', '{"value":"9223372036854775808"}', false],
    'non-finite float' => ['1e400', '1e400', false],
    'invalid original' => ['{"value":', '{"value":1}', false],
    'both invalid' => ['not JSON', 'not JSON', false],
    'empty input' => ['', '', false],
]);
