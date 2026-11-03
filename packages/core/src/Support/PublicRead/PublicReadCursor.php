<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Where a bounded public-read scan stopped, sealed into an opaque token.
 *
 * The token is encrypted and authenticated with the application key, so it
 * reveals nothing about the source (no offsets, positions or how many
 * restricted entries were passed) and cannot be forged or edited. It is bound
 * to the read it continues (the context: query, filters, site, domain,
 * language) and to the public-read eligibility revision it was issued under,
 * and it expires. A token that fails any of those checks opens as null, and
 * the caller starts again at the first page.
 *
 * The position is the source's own keyset (for example the sort value and id
 * of the last entry read), or, for a source that can only be read by page, its
 * page and index. Skip is how many readable entries the requested page still
 * has to pass.
 *
 * @experimental
 */
final readonly class PublicReadCursor
{
    public const int VERSION = 2;

    public const int TTL_SECONDS = 3600;

    private const int MAX_TOKEN_LENGTH = 2048;

    /**
     * @param  array<string, int|string|null>|null  $position  null to start at the beginning of the source
     */
    public function __construct(
        public ?array $position,
        public int $skip = 0,
    ) {}

    /**
     * A fingerprint of the read a cursor belongs to. Every input that changes
     * which entries the read returns, or in what order, must be part of it.
     *
     * @param  array<array-key, mixed>  $parts
     */
    public static function context(string $scope, array $parts): string
    {
        return hash('sha256', $scope . "\n" . json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * Opens a token for this context, or null when it is missing, malformed,
     * tampered with, issued for another read, issued under other public-read
     * rules, or expired.
     */
    public static function open(?string $token, string $context): ?self
    {
        if ($token === null || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        $revision = resolve(PublicReadEligibilityRevision::class)->current();

        if ($revision === null) {
            return null;
        }

        try {
            $padded = strtr($token, '-_', '+/');
            $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
            $data = json_decode(Crypt::decryptString($padded), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || ! is_string($data['c'] ?? null) || ! hash_equals($context, $data['c'])
            || ! is_string($data['r'] ?? null) || ! hash_equals($revision, $data['r'])
            || ! is_int($data['e'] ?? null) || $data['e'] <= now()->getTimestamp()
            || ! is_int($data['s'] ?? null) || $data['s'] < 0
        ) {
            return null;
        }

        $position = $data['p'] ?? null;

        if ($position !== null && ! self::isPosition($position)) {
            return null;
        }

        /** @var array<string, int|string|null>|null $position */
        return new self($position, $data['s']);
    }

    public function seal(string $context): ?string
    {
        $revision = resolve(PublicReadEligibilityRevision::class)->current();

        if ($revision === null) {
            return null;
        }

        $payload = json_encode([
            'v' => self::VERSION,
            'c' => $context,
            'r' => $revision,
            'e' => now()->getTimestamp() + self::TTL_SECONDS,
            'p' => $this->position,
            's' => $this->skip,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(Crypt::encryptString($payload), '+/', '-_'), '=');
    }

    private static function isPosition(mixed $position): bool
    {
        if (! is_array($position) || $position === [] || count($position) > 8) {
            return false;
        }

        return array_all($position, fn ($value, $key): bool => is_string($key) && ($value === null || is_int($value) || is_string($value) && strlen($value) <= 512));
    }
}
