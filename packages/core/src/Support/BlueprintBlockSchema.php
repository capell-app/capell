<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Models\Blueprint;

final class BlueprintBlockSchema
{
    /** @return array<string, mixed> */
    public static function for(Blueprint $blueprint): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => sprintf('%s content blocks', $blueprint->name),
            'description' => 'Block payloads accepted by the Capell content editor for this blueprint.',
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['type'],
                'additionalProperties' => false,
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => resolve(BlueprintBlockTypeRegistry::class)->for(),
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Per-block field schemas are not yet enforced by the Capell editor and are reserved for schema version 2.',
                    ],
                    'content' => [
                        'description' => 'Flat rich content as HTML or a ProseMirror document.',
                        'oneOf' => [
                            ['type' => 'string'],
                            [
                                'type' => 'object',
                                'required' => ['type', 'content'],
                                'properties' => [
                                    'type' => ['const' => 'doc'],
                                    'content' => ['type' => 'array'],
                                ],
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
