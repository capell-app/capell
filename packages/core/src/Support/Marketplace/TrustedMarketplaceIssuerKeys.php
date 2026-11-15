<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use JsonException;

/**
 * The Marketplace issuer public keys a runtime trusts. Production trusts only the keys committed
 * with Core, so a site owner cannot switch enforcement off or sign their own receipts by editing
 * configuration. Outside production, configured keys are added for development and testing.
 */
final class TrustedMarketplaceIssuerKeys
{
    /** @var array<string, array<string, string>> */
    private static array $pinnedByPath = [];

    public function __construct(private readonly ?string $pinnedPath = null) {}

    public static function defaultPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/marketplace/issuer-keys.json';
    }

    public static function production(): bool
    {
        return app()->isProduction();
    }

    public static function flush(): void
    {
        self::$pinnedByPath = [];
    }

    /** @return array<string, string> key id => base64 Ed25519 public key */
    public function all(): array
    {
        $pinned = $this->pinned();
        if (self::production()) {
            return $pinned;
        }

        return $pinned + $this->validKeys(config('capell.extensions.marketplace_receipt_public_keys', []));
    }

    /** @return array<string, string> */
    public function pinned(): array
    {
        $path = $this->pinnedPath ?? self::defaultPath();

        if (! array_key_exists($path, self::$pinnedByPath)) {
            $contents = is_file($path) ? file_get_contents($path) : false;

            try {
                $decoded = is_string($contents) ? json_decode($contents, true, 8, JSON_THROW_ON_ERROR) : null;
            } catch (JsonException) {
                $decoded = null;
            }

            self::$pinnedByPath[$path] = $this->validKeys(is_array($decoded) ? ($decoded['keys'] ?? []) : []);
        }

        return self::$pinnedByPath[$path];
    }

    public function key(string $keyId): ?string
    {
        $encoded = $this->all()[$keyId] ?? null;
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;

        return is_string($decoded) ? $decoded : null;
    }

    /** @return array<string, string> */
    private function validKeys(mixed $keys): array
    {
        if (! is_array($keys)) {
            return [];
        }

        $valid = [];
        foreach ($keys as $id => $key) {
            $decoded = is_string($key) ? base64_decode($key, true) : false;
            if (is_string($id) && $id !== '' && is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $valid[$id] = $key;
            }
        }

        return $valid;
    }
}
