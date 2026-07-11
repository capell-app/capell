<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

class SchemaGraphData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function __construct(
        public array $nodes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => $this->nodes,
        ];
    }

    public function toJsonLdScript(): string
    {
        $json = json_encode(
            $this->toJsonLd(),
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
