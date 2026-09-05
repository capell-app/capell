<?php

declare(strict_types=1);

namespace Capell\Core\Support\Agent;

use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use InvalidArgumentException;

final class AgentToolDefinitionNormalizer
{
    /**
     * Normalise a trusted provider definition or a manifest declaration.
     *
     * The manifest form is deliberately data-only. In particular, binding
     * values are identifiers and paths, never PHP callbacks or executable
     * source.
     *
     * @param  array<string, mixed>|AgentToolDefinitionData  $definition
     */
    public function normalize(array|AgentToolDefinitionData $definition, ?string $ownerPackage = null): AgentToolDefinitionData
    {
        if ($definition instanceof AgentToolDefinitionData) {
            return $this->normalize($definition->toArray(), $ownerPackage ?? $definition->ownerPackage);
        }

        $allowed = ['name', 'description', 'descriptionKey', 'inputSchema', 'outputSchema', 'effect', 'binding', 'ownerPackage'];
        throw_if(array_diff(array_keys($definition), $allowed) !== [], InvalidArgumentException::class, 'Agent tool declaration contains unsupported fields.');

        $name = $this->string($definition['name'] ?? null, 'name');
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9]+)+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Agent tool name [%s] must be a stable lowercase dotted identifier.', $name));
        }

        $description = $this->description($definition);
        $inputSchema = $this->schema($definition['inputSchema'] ?? null, 'inputSchema');
        $outputSchema = $this->schema($definition['outputSchema'] ?? null, 'outputSchema');
        $effect = AgentToolEffect::tryFrom($this->string($definition['effect'] ?? null, 'effect'));
        throw_unless($effect instanceof AgentToolEffect, InvalidArgumentException::class, 'Agent tool effect must be read or write.');

        $binding = $this->binding($definition['binding'] ?? null);

        return new AgentToolDefinitionData(
            name: $name,
            description: $description,
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
            effect: $effect,
            binding: $binding,
            ownerPackage: $ownerPackage ?? $this->nullableString($definition['ownerPackage'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function description(array $definition): string
    {
        $descriptionKey = $this->nullableString($definition['descriptionKey'] ?? null);
        $description = $descriptionKey !== null ? __($descriptionKey) : $this->string($definition['description'] ?? null, 'description');

        throw_unless(is_string($description), InvalidArgumentException::class, 'Agent tool translated description must resolve to a string.');

        throw_if($description === '' || mb_strlen($description) > 500, InvalidArgumentException::class, 'Agent tool description must contain between 1 and 500 characters.');

        return $description;
    }

    /** @return array<string, mixed> */
    private function schema(mixed $schema, string $field): array
    {
        if (! is_array($schema) || (array_is_list($schema) && $schema !== [])) {
            throw new InvalidArgumentException(sprintf('Agent tool %s must be a JSON-schema object.', $field));
        }

        $this->validateSchemaNode($schema, $field);

        return $schema;
    }

    /** @param array<string, mixed> $schema */
    private function validateSchemaNode(array $schema, string $path): void
    {
        $allowed = [
            '$schema', '$ref', 'type', 'title', 'description', 'default', 'enum', 'const',
            'examples', 'properties', 'required', 'additionalProperties', 'items', 'minItems',
            'maxItems', 'minProperties', 'maxProperties', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum',
            'exclusiveMaximum', 'pattern', 'format', 'anyOf', 'oneOf', 'allOf', 'not',
            'definitions', '$defs',
        ];

        if (array_diff(array_keys($schema), $allowed) !== []) {
            throw new InvalidArgumentException(sprintf('Agent tool schema [%s] contains unsupported or executable fields.', $path));
        }

        foreach (['properties', 'definitions', '$defs'] as $childField) {
            if (array_key_exists($childField, $schema)) {
                if (! is_array($schema[$childField]) || (array_is_list($schema[$childField]) && $schema[$childField] !== [])) {
                    throw new InvalidArgumentException(sprintf('Agent tool schema [%s.%s] must be an object.', $path, $childField));
                }

                foreach ($schema[$childField] as $key => $child) {
                    if (! is_string($key) || ! is_array($child)) {
                        throw new InvalidArgumentException(sprintf('Agent tool schema [%s.%s] must contain schema objects.', $path, $childField));
                    }

                    $this->validateSchemaNode($child, $path . '.' . $childField . '.' . $key);
                }
            }
        }

        foreach (['items', 'not'] as $childField) {
            if (array_key_exists($childField, $schema) && is_array($schema[$childField])) {
                $this->validateSchemaNode($schema[$childField], $path . '.' . $childField);
            }
        }

        if (array_key_exists('additionalProperties', $schema) && is_array($schema['additionalProperties'])) {
            $this->validateSchemaNode($schema['additionalProperties'], $path . '.additionalProperties');
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $childField) {
            if (array_key_exists($childField, $schema)) {
                if (! is_array($schema[$childField]) || ! array_is_list($schema[$childField])) {
                    throw new InvalidArgumentException(sprintf('Agent tool schema [%s.%s] must be a list.', $path, $childField));
                }

                foreach ($schema[$childField] as $index => $child) {
                    if (! is_array($child)) {
                        throw new InvalidArgumentException(sprintf('Agent tool schema [%s.%s.%d] must be an object.', $path, $childField, $index));
                    }

                    $this->validateSchemaNode($child, $path . '.' . $childField . '.' . $index);
                }
            }
        }
    }

    private function binding(mixed $binding): AgentToolBindingData
    {
        throw_if(! is_array($binding) || array_is_list($binding), InvalidArgumentException::class, 'Agent tool binding must be an object.');

        throw_if(array_diff(array_keys($binding), ['type', 'target']) !== [], InvalidArgumentException::class, 'Agent tool binding contains unsupported or executable fields.');

        $type = AgentToolBindingType::tryFrom($this->string($binding['type'] ?? null, 'binding.type'));
        throw_unless($type instanceof AgentToolBindingType, InvalidArgumentException::class, 'Agent tool binding type is unsupported.');

        $target = $this->string($binding['target'] ?? null, 'binding.target');
        throw_if(preg_match('/[\x00-\x1F\x7F]/', $target) === 1 || str_contains($target, '::'), InvalidArgumentException::class, 'Agent tool binding target must be a declarative path or identifier.');

        match ($type) {
            AgentToolBindingType::Inline => $this->assertInlineTarget($target),
            AgentToolBindingType::Endpoint => $this->assertEndpointTarget($target),
            AgentToolBindingType::Form => $this->assertStableIdentifier($target, 'form id'),
            AgentToolBindingType::Search, AgentToolBindingType::PropertyQuery => $this->assertStableIdentifier($target, 'binding target'),
        };

        return new AgentToolBindingData(type: $type, target: $target);
    }

    private function assertInlineTarget(string $target): void
    {
        throw_if($target !== 'page', InvalidArgumentException::class, 'Inline agent tool binding target must be page.');
    }

    private function assertEndpointTarget(string $target): void
    {
        throw_if(preg_match('~\A/(?!/)[^\s#]*\z~', $target) !== 1, InvalidArgumentException::class, 'Endpoint agent tool binding target must be an origin-relative path.');
    }

    private function assertStableIdentifier(string $target, string $label): void
    {
        if (preg_match('/\A[a-z][a-z0-9]*(?:[-_.][a-z0-9]+)*\z/', $target) !== 1) {
            throw new InvalidArgumentException(sprintf('Agent tool %s must be a stable identifier.', $label));
        }
    }

    private function string(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Agent tool %s must be a non-empty string.', $field));
        }

        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
