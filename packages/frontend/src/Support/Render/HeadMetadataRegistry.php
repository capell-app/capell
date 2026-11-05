<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use InvalidArgumentException;

final class HeadMetadataRegistry
{
    private const KEYS = ['og:title', 'og:description', 'og:type', 'og:url', 'og:site_name', 'twitter:card', 'twitter:title', 'twitter:description', 'jsonld:WebSite', 'jsonld:WebPage', 'robots'];

    /** @var array<string, string|array<string, mixed>> */
    private array $values = [];

    /** @param string|array<string, mixed> $value */
    public function register(string $key, string|array $value): void
    {
        throw_if(! in_array($key, self::KEYS, true) || (str_starts_with($key, 'jsonld:') !== is_array($value)), InvalidArgumentException::class, 'Invalid head metadata contribution: ' . $key);

        $this->values[$key] = $value;
    }

    /** @param array<string, string|array<string, mixed>> $defaults
     * @return array<string, string|array<string, mixed>>
     */
    public function apply(array $defaults): array
    {
        return array_replace($defaults, $this->values);
    }
}
