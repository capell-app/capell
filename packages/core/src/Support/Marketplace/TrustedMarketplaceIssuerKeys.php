<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use JsonException;

/**
 * The Marketplace issuer public keys a runtime trusts: only the keys committed with Core. They are
 * never read from configuration or the environment, so a site owner cannot sign their own receipts
 * or catalogues by adding a key. Tests inject keys by binding an instance built with $keys.
 */
final class TrustedMarketplaceIssuerKeys
{
    /** @var array<string, string>|null */
    private ?array $pinned = null;

    /** @param array<mixed>|null $keys key id => base64 public key, replacing the committed keys */
    public function __construct(
        private readonly ?string $pinnedPath = null,
        private readonly ?array $keys = null,
    ) {}

    public static function defaultPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/marketplace/issuer-keys.json';
    }

    /** @return array<string, string> key id => base64 Ed25519 public key */
    public function all(): array
    {
        return $this->keys !== null ? $this->validKeys($this->keys) : $this->pinned();
    }

    /** @return array<string, string> */
    public function pinned(): array
    {
        if ($this->pinned !== null) {
            return $this->pinned;
        }

        $path = $this->pinnedPath ?? self::defaultPath();
        $contents = is_file($path) ? file_get_contents($path) : false;

        try {
            $decoded = is_string($contents) ? json_decode($contents, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $decoded = null;
        }

        return $this->pinned = $this->validKeys(is_array($decoded) ? ($decoded['keys'] ?? []) : []);
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
