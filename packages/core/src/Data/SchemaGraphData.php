<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Support\Json\JsonCodec;
use Spatie\LaravelData\Data;

final class SchemaGraphData extends Data
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
        $json = JsonCodec::encode(
            $this->toJsonLd(),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
