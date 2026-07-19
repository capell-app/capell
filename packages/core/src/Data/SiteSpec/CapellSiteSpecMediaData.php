<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

final class CapellSiteSpecMediaData extends Data
{
    /**
     * Images are keyed by the page slug that will own the imported image.
     *
     * @param  array<string, string>  $images
     */
    public function __construct(
        public readonly ?string $sourceUrl = null,
        public readonly ?string $logo = null,
        public readonly array $images = [],
    ) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return ['images' => ['sometimes', 'array']];
    }

    public function hasRemoteAssets(): bool
    {
        return $this->logo !== null || $this->images !== [];
    }
}
