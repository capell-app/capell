<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

class PageUrlSiteDomainDiagnosticData extends Data
{
    public function __construct(
        public ?int $pageUrlId,
        public ?int $pageableId,
        public ?string $pageableType,
        public ?int $siteId,
        public ?int $languageId,
        public bool $siteDomainRelationLoaded,
        public bool $loadedSiteDomainIsNull,
        public bool $activeSiteDomainExists,
        public ?int $activeSiteDomainId,
        public bool $trashedSiteDomainExists,
        public ?int $trashedSiteDomainId,
        public ?string $trashedSiteDomainDeletedAt,
        public ?string $routeName,
        public ?string $requestPath,
        public ?string $caller,
    ) {}

    /**
     * @return array<string, bool|int|string|null>
     */
    public function toLogContext(): array
    {
        return [
            'page_url_id' => $this->pageUrlId,
            'pageable_id' => $this->pageableId,
            'pageable_type' => $this->pageableType,
            'site_id' => $this->siteId,
            'language_id' => $this->languageId,
            'site_domain_relation_loaded' => $this->siteDomainRelationLoaded,
            'loaded_site_domain_is_null' => $this->loadedSiteDomainIsNull,
            'active_site_domain_exists' => $this->activeSiteDomainExists,
            'active_site_domain_id' => $this->activeSiteDomainId,
            'trashed_site_domain_exists' => $this->trashedSiteDomainExists,
            'trashed_site_domain_id' => $this->trashedSiteDomainId,
            'trashed_site_domain_deleted_at' => $this->trashedSiteDomainDeletedAt,
            'route_name' => $this->routeName,
            'request_path' => $this->requestPath,
            'caller' => $this->caller,
        ];
    }
}
