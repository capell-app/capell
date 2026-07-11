<?php

declare(strict_types=1);

namespace Capell\Core\Support;

final class CapellSiteSpecSchema
{
    /** @return array<string, mixed> */
    public static function toArray(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $colour = ['type' => ['string', 'null'], 'pattern' => '^#[0-9A-Fa-f]{6}$'];

        return [
            'type' => 'object',
            'required' => ['site', 'theme', 'pages'],
            'properties' => [
                'initialVisibility' => ['type' => 'string', 'enum' => ['private', 'public'], 'default' => 'private'],
                'acknowledgePublic' => ['type' => 'boolean', 'default' => false],
                'site' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1], 'businessName' => $nullableString,
                    'organisationType' => $nullableString, 'description' => $nullableString,
                ]],
                'theme' => ['type' => 'object', 'required' => ['key'], 'properties' => [
                    'key' => ['type' => 'string', 'minLength' => 1],
                    'colors' => ['type' => 'object', 'properties' => ['primary' => $colour, 'secondary' => $colour, 'accent' => $colour]],
                    'fontFamily' => $nullableString, 'linkColor' => $nullableString, 'linkColorActive' => $nullableString,
                    'container' => $nullableString, 'customCss' => $nullableString,
                ]],
                'language' => ['type' => 'object', 'properties' => [
                    'code' => ['type' => 'string', 'default' => 'en'], 'name' => ['type' => 'string', 'default' => 'English'],
                    'locale' => ['type' => 'string', 'default' => 'en_GB'], 'flag' => ['type' => 'string', 'default' => 'gb'],
                    'default' => ['type' => 'boolean', 'default' => true],
                ]],
                'pages' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 15, 'items' => [
                    'type' => 'object', 'required' => ['name', 'slug', 'title', 'pageType'], 'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 1],
                        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                        'title' => ['type' => 'string', 'minLength' => 1], 'pageType' => ['type' => 'string'],
                        'url' => $nullableString, 'description' => $nullableString, 'order' => ['type' => 'integer', 'default' => 0],
                        'contentStructure' => ['type' => 'string', 'enum' => ['html', 'blocks'], 'default' => 'html'],
                        'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['type', 'content'], 'properties' => [
                            'type' => ['type' => 'string'], 'content' => ['type' => 'string', 'maxLength' => 20000],
                            'title' => $nullableString, 'summary' => $nullableString, 'order' => ['type' => 'integer', 'default' => 0],
                            'meta' => ['type' => 'object'],
                        ]]], 'visibility' => ['type' => 'object'], 'meta' => ['type' => 'object'],
                    ],
                ]],
            ],
        ];
    }
}
