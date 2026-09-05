<?php

declare(strict_types=1);

namespace Capell\Core\Data\Agent;

use Capell\Core\Enums\Agent\AgentToolEffect;
use Override;
use Spatie\LaravelData\Data;

final class AgentToolDefinitionData extends Data
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly array $outputSchema,
        public readonly AgentToolEffect $effect,
        public readonly AgentToolBindingData $binding,
        public readonly ?string $ownerPackage = null,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return $this->toPublicArray();
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
            'effect' => $this->effect->value,
            'binding' => $this->binding->toArray(),
        ];
    }

    public function withOwnerPackage(string $ownerPackage): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            inputSchema: $this->inputSchema,
            outputSchema: $this->outputSchema,
            effect: $this->effect,
            binding: $this->binding,
            ownerPackage: $ownerPackage,
        );
    }
}
