<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

/**
 * Where a bounded public-read scan stopped: the position in the underlying
 * ordered source to continue from, and how many readable entries still need
 * to be skipped to reach the requested page. Encoded as an opaque token.
 *
 * @experimental
 */
final readonly class PublicReadCursor
{
    public function __construct(
        public int $offset,
        public int $skip = 0,
    ) {}

    public static function decode(?string $token): ?self
    {
        if ($token === null || $token === '' || strlen($token) > 200) {
            return null;
        }

        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_int($data['o'] ?? null) || ! is_int($data['s'] ?? null) || $data['o'] < 0 || $data['s'] < 0) {
            return null;
        }

        return new self($data['o'], $data['s']);
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['o' => $this->offset, 's' => $this->skip])), '+/', '-_'), '=');
    }
}
