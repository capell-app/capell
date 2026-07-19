<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Models\Blueprint;

final class BlueprintBlockTypeRegistry
{
    /** @var array<string, true> */
    private array $types = ['content' => true];

    public function register(string $type): self
    {
        if ($type !== '') {
            $this->types[$type] = true;
        }

        return $this;
    }

    /** @return list<string> */
    public function for(Blueprint $blueprint): array
    {
        $types = array_keys($this->types);
        sort($types);

        return $types;
    }
}
