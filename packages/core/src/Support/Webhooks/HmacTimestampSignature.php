<?php

declare(strict_types=1);

namespace Capell\Core\Support\Webhooks;

use Capell\Core\Exceptions\InboundWebhookRejectedException;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * The default webhook signature: a header of the form `t=<unix seconds>,v1=<hex>`
 * where the hex value is HMAC-SHA256 of `<t>.<raw body>` under a shared secret.
 *
 * Signing the timestamp with the body makes the replay window enforceable: a
 * captured delivery cannot be resent with a fresh timestamp, and one older (or
 * further in the future) than the tolerance is refused. Several `v1` values and
 * several secrets are accepted so a secret can be rotated without downtime.
 */
final readonly class HmacTimestampSignature
{
    public const int DEFAULT_TOLERANCE_SECONDS = 300;

    public const int MAX_TOLERANCE_SECONDS = 3600;

    public function __construct(public int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS)
    {
        throw_if($toleranceSeconds < 1 || $toleranceSeconds > self::MAX_TOLERANCE_SECONDS, InvalidArgumentException::class, sprintf('A webhook replay window must be between 1 and %d seconds.', self::MAX_TOLERANCE_SECONDS));
    }

    public static function sign(string $body, string $secret, int $timestamp): string
    {
        throw_if($secret === '', InvalidArgumentException::class, 'A webhook secret must not be empty.');

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, $secret));
    }

    /**
     * Returns the signed timestamp when the header is valid for the body under any
     * of the secrets and inside the replay window.
     *
     * @param  list<string>  $secrets
     *
     * @throws InboundWebhookRejectedException
     */
    public function verify(?string $header, string $body, array $secrets, ?int $now = null): int
    {
        $secrets = array_values(array_filter($secrets, static fn (string $secret): bool => $secret !== ''));

        throw_if($secrets === [], InboundWebhookRejectedException::because(InboundWebhookRejectedException::REASON_SIGNATURE, 'no signing secret is configured'));

        [$timestamp, $signatures] = $this->parse($header ?? '');
        $now ??= Date::now()->getTimestamp();

        throw_if(abs($now - $timestamp) > $this->toleranceSeconds, InboundWebhookRejectedException::because(InboundWebhookRejectedException::REASON_TIMESTAMP, 'the signed timestamp is outside the replay window'));

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return $timestamp;
                }
            }
        }

        throw InboundWebhookRejectedException::because(InboundWebhookRejectedException::REASON_SIGNATURE, 'the signature does not match');
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't' && $timestamp === null && preg_match('/^\d{1,12}$/D', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && preg_match('/^[0-9a-f]{64}$/D', $value) === 1) {
                $signatures[] = $value;
            }
        }

        throw_if($timestamp === null || $signatures === [], InboundWebhookRejectedException::because(InboundWebhookRejectedException::REASON_MALFORMED, 'the signature header is missing or malformed'));

        return [$timestamp, $signatures];
    }
}
