<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use ArrayAccess;
use LogicException;
use Override;
use Spatie\LaravelData\Data;

/**
 * @implements ArrayAccess<string, list<class-string>>
 */
final class ExtensionProviderData extends Data implements ArrayAccess
{
    /** @var list<string> */
    private const array Buckets = ['metadata', 'install', 'runtime', 'auth', 'admin', 'frontend'];

    /**
     * @param  list<class-string>  $metadata
     * @param  list<class-string>  $install
     * @param  list<class-string>  $runtime
     * @param  list<class-string>  $auth
     * @param  list<class-string>  $admin
     * @param  list<class-string>  $frontend
     */
    public function __construct(
        public readonly array $metadata = [],
        public readonly array $install = [],
        public readonly array $runtime = [],
        public readonly array $auth = [],
        public readonly array $admin = [],
        public readonly array $frontend = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            metadata: self::classList($data['metadata'] ?? []),
            install: self::classList($data['install'] ?? []),
            runtime: self::classList($data['runtime'] ?? []),
            auth: self::classList($data['auth'] ?? []),
            admin: self::classList($data['admin'] ?? []),
            frontend: self::classList($data['frontend'] ?? []),
        );
    }

    /**
     * @return array{
     *     metadata: list<class-string>,
     *     install: list<class-string>,
     *     runtime: list<class-string>,
     *     auth: list<class-string>,
     *     admin: list<class-string>,
     *     frontend: list<class-string>
     * }
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'metadata' => $this->metadata,
            'install' => $this->install,
            'runtime' => $this->runtime,
            'auth' => $this->auth,
            'admin' => $this->admin,
            'frontend' => $this->frontend,
        ];
    }

    /** @return list<class-string> */
    #[Override]
    public function all(): array
    {
        return [
            ...$this->metadata,
            ...$this->install,
            ...$this->runtime,
            ...$this->auth,
            ...$this->admin,
            ...$this->frontend,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, self::Buckets, true);
    }

    /** @return list<class-string> */
    public function offsetGet(mixed $offset): array
    {
        return match ($offset) {
            'metadata' => $this->metadata,
            'install' => $this->install,
            'runtime' => $this->runtime,
            'auth' => $this->auth,
            'admin' => $this->admin,
            'frontend' => $this->frontend,
            default => [],
        };
    }

    /** @param list<class-string> $value */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException(self::class . ' is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException(self::class . ' is immutable.');
    }

    /** @return list<class-string> */
    private static function classList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var list<class-string> $classes */
        $classes = array_values(array_filter($value, is_string(...)));

        return $classes;
    }
}
