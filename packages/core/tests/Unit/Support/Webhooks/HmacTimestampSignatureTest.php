<?php

declare(strict_types=1);

use Capell\Core\Exceptions\InboundWebhookRejectedException;
use Capell\Core\Support\Webhooks\HmacTimestampSignature;

const HMAC_SIGNED_AT = 1_800_000_000;

function hmacRejectionReason(Closure $verify): ?string
{
    try {
        $verify();
    } catch (InboundWebhookRejectedException $inboundWebhookRejectedException) {
        return $inboundWebhookRejectedException->reason;
    }

    return null;
}

it('accepts a body signed with the secret inside the replay window', function (int $now): void {
    $header = HmacTimestampSignature::sign('{"id":"evt_1"}', 'secret-one', HMAC_SIGNED_AT);

    expect((new HmacTimestampSignature)->verify($header, '{"id":"evt_1"}', ['secret-one'], $now))->toBe(HMAC_SIGNED_AT);
})->with([
    'at signing' => HMAC_SIGNED_AT,
    'at the end of the window' => HMAC_SIGNED_AT + 300,
    'with the sender clock slightly ahead' => HMAC_SIGNED_AT - 300,
]);

it('refuses a delivery whose signed timestamp is outside the replay window', function (int $now): void {
    $header = HmacTimestampSignature::sign('{}', 'secret-one', HMAC_SIGNED_AT);

    expect(hmacRejectionReason(fn (): int => (new HmacTimestampSignature)->verify($header, '{}', ['secret-one'], $now)))
        ->toBe(InboundWebhookRejectedException::REASON_TIMESTAMP);
})->with([
    'replayed later' => HMAC_SIGNED_AT + 301,
    'from the future' => HMAC_SIGNED_AT - 301,
]);

it('refuses a body, timestamp or secret that does not match the signature', function (string $header, string $body, array $secrets): void {
    expect(hmacRejectionReason(fn (): int => (new HmacTimestampSignature)->verify($header, $body, $secrets, HMAC_SIGNED_AT)))
        ->toBe(InboundWebhookRejectedException::REASON_SIGNATURE);
})->with([
    'changed body' => [HmacTimestampSignature::sign('{"amount":1}', 's', HMAC_SIGNED_AT), '{"amount":100}', ['s']],
    'timestamp moved without re-signing' => [str_replace('t=' . HMAC_SIGNED_AT, 't=' . (HMAC_SIGNED_AT + 10), HmacTimestampSignature::sign('{}', 's', HMAC_SIGNED_AT)), '{}', ['s']],
    'other secret' => [HmacTimestampSignature::sign('{}', 'attacker', HMAC_SIGNED_AT), '{}', ['s']],
    'no secret configured' => [HmacTimestampSignature::sign('{}', 's', HMAC_SIGNED_AT), '{}', ['']],
]);

it('refuses a missing or malformed signature header', function (?string $header): void {
    expect(hmacRejectionReason(fn (): int => (new HmacTimestampSignature)->verify($header, '{}', ['s'], HMAC_SIGNED_AT)))
        ->toBe(InboundWebhookRejectedException::REASON_MALFORMED);
})->with([
    'missing' => null,
    'empty' => '',
    'no timestamp' => 'v1=' . str_repeat('a', 64),
    'no signature' => 't=' . HMAC_SIGNED_AT,
    'short signature' => 't=' . HMAC_SIGNED_AT . ',v1=abc',
    'non-numeric timestamp' => 't=soon,v1=' . str_repeat('a', 64),
]);

it('accepts any current secret and any listed signature, so secrets can rotate', function (): void {
    $newSignature = explode('v1=', HmacTimestampSignature::sign('{}', 'new', HMAC_SIGNED_AT))[1];
    $header = sprintf('t=%d,v1=%s,v1=%s', HMAC_SIGNED_AT, str_repeat('0', 64), $newSignature);

    expect((new HmacTimestampSignature)->verify($header, '{}', ['old', 'new'], HMAC_SIGNED_AT))->toBe(HMAC_SIGNED_AT);
});

it('only allows a replay window between one second and one hour', function (int $seconds): void {
    expect(fn (): HmacTimestampSignature => new HmacTimestampSignature($seconds))->toThrow(InvalidArgumentException::class);
})->with([0, -1, 3601]);
