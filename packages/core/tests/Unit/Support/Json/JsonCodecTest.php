<?php

declare(strict_types=1);

use Capell\Core\Support\Json\JsonCodec;

it('encodes throwing on error', function (): void {
    expect(JsonCodec::encode(['key' => 1]))->toBe('{"key":1}')
        ->and(JsonCodec::encode('value'))->toBe('"value"')
        ->and(JsonCodec::encodeOrDefault(["invalid\xB1"], 'fallback'))->toBe('fallback')
        ->and(JsonCodec::encodeOrFalse(["invalid\xB1"]))->toBeFalse();
});

it('decodes strict values and reports malformed json', function (): void {
    expect(JsonCodec::decode('{"key":1}'))->toBe(['key' => 1])
        ->and(JsonCodec::decode('{"key":1}', associative: false))->toBeInstanceOf(stdClass::class)
        ->and(JsonCodec::decodeOrRaw('not json'))->toBe('not json')
        ->and(fn (): mixed => JsonCodec::decode('not json'))->toThrow(JsonException::class);
});

it('decodes returning the default for non-arrays', function (): void {
    expect(JsonCodec::decodeArray('not json', default: ['fallback']))->toBe(['fallback']);
    expect(JsonCodec::decodeArray('null', default: []))->toBe([]);
    expect(JsonCodec::decodeArray('{"key":1}', default: []))->toBe(['key' => 1]);
});

it('decodes typed object roots without accepting lists', function (): void {
    expect(JsonCodec::decodeObject('{"key":1}'))->toBe(['key' => 1])
        ->and(JsonCodec::decodeObject('["value"]', ['fallback' => true]))->toBe(['fallback' => true])
        ->and(JsonCodec::decodeObject('not json', ['fallback' => true]))->toBe(['fallback' => true])
        ->and(JsonCodec::decodeObjectOrFail('{"key":1}'))->toBe(['key' => 1])
        ->and(fn (): array => JsonCodec::decodeObjectOrFail('["value"]'))->toThrow(UnexpectedValueException::class);
});

it('decodes array roots strictly', function (): void {
    expect(JsonCodec::decodeArrayOrFail('["value"]'))->toBe(['value'])
        ->and(fn (): array => JsonCodec::decodeArrayOrFail('true'))->toThrow(UnexpectedValueException::class);
});

it('decodes scalar roots without accepting structured values', function (): void {
    expect(JsonCodec::decodeScalar('"value"'))->toBe('value')
        ->and(JsonCodec::decodeScalar('12'))->toBe(12)
        ->and(JsonCodec::decodeScalar('false'))->toBeFalse()
        ->and(JsonCodec::decodeScalar('{"key":1}', 'fallback'))->toBe('fallback')
        ->and(JsonCodec::decodeScalar('not json', 'fallback'))->toBe('fallback');
});

it('returns default for null and empty input', function (): void {
    expect(JsonCodec::decodeArray(null, default: ['nullfallback']))->toBe(['nullfallback']);
    expect(JsonCodec::decodeArray('', default: ['emptyfallback']))->toBe(['emptyfallback']);
});
