<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use InvalidArgumentException;

final readonly class ExtensionPosition
{
    private function __construct(
        public string $kind,
        public int $priority = 0,
        public ?string $anchor = null,
    ) {}

    public static function first(): self
    {
        return new self('first');
    }

    public static function last(): self
    {
        return new self('last');
    }

    public static function priority(int $priority): self
    {
        return new self('priority', $priority);
    }

    public static function before(string $key): self
    {
        return new self('before', anchor: self::anchor($key));
    }

    public static function after(string $key): self
    {
        return new self('after', anchor: self::anchor($key));
    }

    private static function anchor(string $key): string
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('Extension position anchors must not be empty.');
        }

        return $key;
    }
}
