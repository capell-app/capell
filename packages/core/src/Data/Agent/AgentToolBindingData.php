<?php

declare(strict_types=1);

namespace Capell\Core\Data\Agent;

use Capell\Core\Enums\Agent\AgentToolBindingType;
use Override;
use Spatie\LaravelData\Data;

final class AgentToolBindingData extends Data
{
    public function __construct(
        public readonly AgentToolBindingType $type,
        public readonly string $target,
    ) {}

    /** @return array{type: string, target: string} */
    #[Override]
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'target' => $this->target,
        ];
    }
}
