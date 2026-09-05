<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Capell\Core\Actions\Properties\SyncBuiltInPropertySetsAction;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;

/**
 * Core's own built-in property sets: `commerce.product`, `events.event`,
 * `content.article`. {@see SyncBuiltInPropertySetsAction}
 * upserts these into the database on install/upgrade.
 */
final class BuiltInPropertySets
{
    public const string OWNER_PACKAGE = 'capell/core';

    /**
     * @return array<string, array{name: string, definitions: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'commerce.product' => [
                'name' => __('capell-core::properties.builtin.commerce_product.name'),
                'definitions' => [
                    [
                        'key' => 'price',
                        'type' => PropertyType::Money,
                        'semantic' => 'schema:price',
                        'requirement' => PropertyRequirement::Contract,
                        'locked' => true,
                        'description' => __('capell-core::properties.builtin.commerce_product.price'),
                        'unit_config' => null,
                    ],
                    [
                        'key' => 'sku',
                        'type' => PropertyType::Text,
                        'semantic' => 'schema:sku',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.commerce_product.sku'),
                        'unit_config' => null,
                    ],
                    [
                        'key' => 'weight',
                        'type' => PropertyType::Dimension,
                        'semantic' => 'schema:weight',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.commerce_product.weight'),
                        'unit_config' => ['allowed' => ['g', 'kg', 'lb', 'oz']],
                    ],
                    [
                        'key' => 'availability',
                        'type' => PropertyType::Text,
                        'semantic' => 'schema:availability',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.commerce_product.availability'),
                        'unit_config' => null,
                    ],
                ],
            ],
            'events.event' => [
                'name' => __('capell-core::properties.builtin.events_event.name'),
                'definitions' => [
                    [
                        'key' => 'startDate',
                        'type' => PropertyType::DateTime,
                        'semantic' => 'schema:startDate',
                        'requirement' => PropertyRequirement::Contract,
                        'locked' => true,
                        'description' => __('capell-core::properties.builtin.events_event.start_date'),
                        'unit_config' => null,
                    ],
                    [
                        'key' => 'endDate',
                        'type' => PropertyType::DateTime,
                        'semantic' => 'schema:endDate',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.events_event.end_date'),
                        'unit_config' => null,
                    ],
                    [
                        'key' => 'venue',
                        'type' => PropertyType::Text,
                        'semantic' => 'schema:location',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.events_event.venue'),
                        'unit_config' => null,
                    ],
                ],
            ],
            'content.article' => [
                'name' => __('capell-core::properties.builtin.content_article.name'),
                'definitions' => [
                    [
                        'key' => 'author',
                        'type' => PropertyType::Text,
                        'semantic' => 'schema:author',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.content_article.author'),
                        'unit_config' => null,
                    ],
                    [
                        'key' => 'datePublished',
                        'type' => PropertyType::Date,
                        'semantic' => 'schema:datePublished',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.content_article.date_published'),
                        'unit_config' => null,
                    ],
                    [
                        'key' => 'wordCount',
                        'type' => PropertyType::Number,
                        'semantic' => 'schema:wordCount',
                        'requirement' => PropertyRequirement::None,
                        'locked' => false,
                        'description' => __('capell-core::properties.builtin.content_article.word_count'),
                        'unit_config' => null,
                    ],
                ],
            ],
        ];
    }
}
