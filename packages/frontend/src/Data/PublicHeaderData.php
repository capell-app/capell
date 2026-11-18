<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

final class PublicHeaderData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly string $homeUrl,
        public readonly string $siteDisplayName,
        public readonly ?string $logoView,
        public readonly ?string $renderedMediaLogo,
    ) {}
}
