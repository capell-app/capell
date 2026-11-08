<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support\DomainProof;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Holds the single-use tokens capell.app sends this site so it can prove it
 * controls what its own host serves. Only tokens received from capell.app over
 * this site's own authenticated requests are stored, each bound to the host
 * capell.app will fetch it from and kept for at most
 * {@see self::MAX_TTL_SECONDS}. Answering for a token reveals nothing else.
 */
final class DomainProofChallengeStore
{
    public const string TOKEN_PATTERN = '[A-Za-z0-9]{32,128}';

    private const int MAX_TTL_SECONDS = 900;

    private const string CACHE_PREFIX = 'capell-marketplace:domain-proof:';

    /** Stores the `domain_proof` value of a capell.app response, if it carries a valid one. */
    public function rememberFromResponse(mixed $domainProof): void
    {
        if (! is_array($domainProof)) {
            return;
        }

        $token = $domainProof['token'] ?? null;
        $expiresAt = $domainProof['expires_at'] ?? null;
        $host = $this->normaliseHost($domainProof['host'] ?? null);

        if (! is_string($token) || preg_match('/\A' . self::TOKEN_PATTERN . '\z/', $token) !== 1 || ! is_string($expiresAt) || $host === null) {
            return;
        }

        try {
            $seconds = (int) min(self::MAX_TTL_SECONDS, CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($expiresAt), false));
        } catch (Throwable) {
            return;
        }

        if ($seconds <= 0) {
            return;
        }

        Cache::put($this->key($token), $host, $seconds);
    }

    /** Whether this token was sent for the host the request arrived on. */
    public function holds(string $token, string $requestHost): bool
    {
        if (preg_match('/\A' . self::TOKEN_PATTERN . '\z/', $token) !== 1) {
            return false;
        }

        $host = Cache::get($this->key($token));
        $requestHost = $this->normaliseHost($requestHost);

        return is_string($host) && $requestHost !== null && hash_equals($host, $requestHost);
    }

    private function normaliseHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim(trim($host), '.'));

        return $host !== '' && strlen($host) <= 253 && preg_match('/\A[a-z0-9.-]+\z/', $host) === 1 ? $host : null;
    }

    private function key(string $token): string
    {
        return self::CACHE_PREFIX . hash('sha256', $token);
    }
}
