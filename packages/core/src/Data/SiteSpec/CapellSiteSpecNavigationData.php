<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

final class CapellSiteSpecNavigationData extends Data
{
    /**
     * @param  list<string>  $pageSlugs
     */
    public function __construct(
        public readonly string $key,
        public readonly array $pageSlugs,
        public readonly ?string $name = null,
    ) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return [
            'pageSlugs' => ['array'],
            'pageSlugs.*' => ['string', 'distinct'],
        ];
    }
}
