<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

final class PublicViewQueryCapture
{
    /** @var list<array<string, mixed>> */
    private array $queries = [];

    public function flush(): void
    {
        $this->queries = [];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function record(array $query): void
    {
        $this->queries[] = $query;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->queries;
    }
}
