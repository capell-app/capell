<?php

declare(strict_types=1);

namespace Capell\Core\Data\PublicRead;

/**
 * A page address considered for an anonymous public projection.
 *
 * The URL is the address the projection would publish, absolute whenever the
 * caller knows its domain. Pageable identity is optional because some
 * projections only hold the published address.
 *
 * $publishedOn is the absolute base URL of the domain the projection is
 * published on. A relative $url is resolved against it, and is denied when it
 * is missing: a relative address is never guessed onto another domain.
 */
final readonly class PublicPageReadCandidateData
{
    public function __construct(
        public string $url,
        public ?int $siteId = null,
        public ?int $languageId = null,
        public ?string $pageableType = null,
        public int|string|null $pageableId = null,
        public ?string $publishedOn = null,
    ) {}
}
