<?php

declare(strict_types=1);

namespace Capell\Core\Attributes;

use Attribute;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Support\Agent\AgentToolDefinitionNormalizer;
use InvalidArgumentException;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AgentTool
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $descriptionKey = null,
        public array $inputSchema = [],
        public array $outputSchema = [],
        public AgentToolEffect $effect = AgentToolEffect::Read,
        public AgentToolBindingType $bindingType = AgentToolBindingType::Endpoint,
        public string $bindingTarget = '',
    ) {}

    /** @param class-string $class */
    public static function for(string $class): AgentToolDefinitionData
    {
        $attributes = new ReflectionClass($class)->getAttributes(self::class);
        $attribute = $attributes[0] ?? null;

        if ($attribute === null) {
            throw new InvalidArgumentException(sprintf('Agent tool class [%s] must declare #[AgentTool].', $class));
        }

        return $attribute->newInstance()->definition();
    }

    public function definition(): AgentToolDefinitionData
    {
        $declaration = [
            'name' => $this->name,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
            'effect' => $this->effect->value,
            'binding' => [
                'type' => $this->bindingType->value,
                'target' => $this->bindingTarget,
            ],
        ];

        if ($this->description !== null) {
            $declaration['description'] = $this->description;
        }

        if ($this->descriptionKey !== null) {
            $declaration['descriptionKey'] = $this->descriptionKey;
        }

        return (new AgentToolDefinitionNormalizer)->normalize($declaration);
    }
}
