<?php

declare(strict_types=1);

use Capell\Core\Support\Json\JsonCodec;

it('encodes throwing on error', function (): void {
    expect(JsonCodec::encode(['key' => 1]))->toBe('{"key":1}');
});

it('decodes returning the default for non-arrays', function (): void {
    expect(JsonCodec::decodeArray('not json', default: ['fallback']))->toBe(['fallback']);
    expect(JsonCodec::decodeArray('null', default: []))->toBe([]);
    expect(JsonCodec::decodeArray('{"key":1}', default: []))->toBe(['key' => 1]);
});

it('returns default for null and empty input', function (): void {
    expect(JsonCodec::decodeArray(null, default: ['nullfallback']))->toBe(['nullfallback']);
    expect(JsonCodec::decodeArray('', default: ['emptyfallback']))->toBe(['emptyfallback']);
});
