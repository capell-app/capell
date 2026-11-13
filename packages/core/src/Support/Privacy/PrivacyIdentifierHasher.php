<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use RuntimeException;

/**
 * Keyed hashes of a person's identifier (an email address), so a package can store and look
 * people up by a hash instead of the address itself: the address then never appears in an
 * index, a query log or a slow-query report.
 *
 * The key is capell.privacy.identifier_key, or the application key when that is unset. Keys
 * listed in capell.privacy.identifier_previous_keys (or app.previous_keys when the application
 * key is used) still match, so rotating the key does not hide rows hashed under the old one;
 * rehash those rows to the current key when convenient.
 */
final class PrivacyIdentifierHasher
{
    /** The hash an identifier is stored under now. */
    public static function hash(string $identifier): string
    {
        return self::hashWith(self::normalise($identifier), self::currentKey());
    }

    /**
     * Every hash the identifier may be stored under: the current key's first, then each
     * previous key's.
     *
     * @return non-empty-list<string>
     */
    public static function candidates(string $identifier): array
    {
        $normalised = self::normalise($identifier);
        $hashes = [self::hashWith($normalised, self::currentKey())];

        foreach (self::previousKeys() as $key) {
            $hashes[] = self::hashWith($normalised, $key);
        }

        return array_values(array_unique($hashes));
    }

    private static function normalise(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    private static function hashWith(string $identifier, string $key): string
    {
        return hash_hmac('sha256', $identifier, $key);
    }

    private static function currentKey(): string
    {
        $configured = config('capell.privacy.identifier_key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $appKey = config('app.key');

        if (is_string($appKey) && $appKey !== '') {
            return $appKey;
        }

        // A guessable key would make every stored hash reversible by trying addresses.
        throw new RuntimeException('Capell cannot hash privacy identifiers: set capell.privacy.identifier_key (CAPELL_PRIVACY_IDENTIFIER_KEY) or app.key.');
    }

    /** @return list<string> */
    private static function previousKeys(): array
    {
        $configured = config('capell.privacy.identifier_key');
        $previous = is_string($configured) && $configured !== ''
            ? config('capell.privacy.identifier_previous_keys', [])
            : config('app.previous_keys', []);

        return array_values(array_filter(
            is_array($previous) ? $previous : [],
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        ));
    }
}
