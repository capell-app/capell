<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

final readonly class SqliteJsonTraversal
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $from,
        public string $value,
        public string $valuePath,
        public array $bindings,
    ) {}
}
