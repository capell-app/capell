<?php

declare(strict_types=1);

namespace Capell\Core\Data\Database;

final readonly class SqlFragment
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}

    public static function raw(string $sql): self
    {
        return new self($sql);
    }

    public static function value(mixed $value): self
    {
        return new self('?', [$value]);
    }
}
