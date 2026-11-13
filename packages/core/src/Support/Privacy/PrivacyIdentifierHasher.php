<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Normalizer;
use RuntimeException;

/**
 * Keyed hashes of a person's identifier (an email address), so a package can store and look
 * people up by a hash instead of the address itself: the address then never appears in an
 * index, a query log or a slow-query report.
 *
 * The key is capell.privacy.identifier_key, or the application key when that is unset. Lookups
 * also try every earlier key: capell.privacy.identifier_previous_keys, the application key and
 * app.previous_keys. A site that ran on the application key and later sets a dedicated key
 * therefore still finds the rows it hashed before. Never remove a key from those lists until
 * every row hashed under it has been rehashed with RehashPrivacyIdentifiersAction: a row hashed
 * under a removed key can no longer be found, so an export or erasure would silently miss it.
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
     * earlier key's.
     *
     * @return non-empty-list<string>
     */
    public static function candidates(string $identifier): array
    {
        $normalised = self::normalise($identifier);
        $hashes = [];

        foreach ([self::currentKey(), ...self::previousKeys()] as $key) {
            $hashes[] = self::hashWith($normalised, $key);
        }

        return array_values(array_unique($hashes));
    }

    /**
     * One form per address: trimmed, Unicode-composed (NFC), lower case, with an
     * internationalised domain in its ASCII (punycode) form. Plus addressing is kept, because
     * whether jane+news@ and jane@ reach the same person depends on the mail provider.
     */
    public static function normalise(string $identifier): string
    {
        $identifier = trim($identifier);
        $composed = Normalizer::normalize($identifier, Normalizer::FORM_C);
        $identifier = mb_strtolower(is_string($composed) ? $composed : $identifier);
        $at = strrpos($identifier, '@');

        if ($at === false) {
            return $identifier;
        }

        $domain = substr($identifier, $at + 1);
        $ascii = $domain === '' ? false : idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return substr($identifier, 0, $at + 1) . (is_string($ascii) ? strtolower($ascii) : $domain);
    }

    private static function hashWith(string $identifier, string $key): string
    {
        return hash_hmac('sha256', $identifier, $key);
    }

    private static function currentKey(): string
    {
        $dedicated = self::dedicatedKey();

        if ($dedicated !== null) {
            return $dedicated;
        }

        $appKey = self::applicationKey();

        if ($appKey !== null) {
            return $appKey;
        }

        // A guessable key would make every stored hash reversible by trying addresses.
        throw new RuntimeException('Capell cannot hash privacy identifiers: set capell.privacy.identifier_key (CAPELL_PRIVACY_IDENTIFIER_KEY) or app.key.');
    }

    /**
     * Earlier keys, newest first: the dedicated previous keys, then (when a dedicated key is
     * set) the application key it replaced, then the application's previous keys.
     *
     * @return list<string>
     */
    private static function previousKeys(): array
    {
        $keys = self::keyList(config('capell.privacy.identifier_previous_keys', []));

        $applicationKey = self::applicationKey();

        if (self::dedicatedKey() !== null && $applicationKey !== null) {
            $keys[] = $applicationKey;
        }

        return [...$keys, ...self::keyList(config('app.previous_keys', []))];
    }

    private static function dedicatedKey(): ?string
    {
        $key = config('capell.privacy.identifier_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private static function applicationKey(): ?string
    {
        $key = config('app.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** @return list<string> */
    private static function keyList(mixed $keys): array
    {
        $list = [];

        foreach (is_array($keys) ? $keys : [] as $key) {
            if (is_string($key) && trim($key) !== '') {
                $list[] = trim($key);
            }
        }

        return $list;
    }
}
