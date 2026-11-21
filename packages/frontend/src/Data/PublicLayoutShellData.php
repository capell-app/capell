<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Enums\ContentStructure;
use Spatie\LaravelData\Data;

/** The public layout shell: chrome selectors, branding and system-page content, without records or identifiers. */
final class PublicLayoutShellData extends Data implements PublicRenderData
{
    /** @param  array<array-key, mixed>|string  $pageContent */
    public function __construct(
        public readonly bool $isSystemPageLayout,
        public readonly string $homeUrl,
        public readonly ?string $siteDisplayName,
        public readonly ?string $logoView,
        public readonly ?string $renderedMediaLogo,
        public readonly string $pageTitle,
        public readonly array|string $pageContent,
        public readonly ContentStructure $pageContentStructure,
        public readonly ?string $headerFile,
        public readonly bool $headerFileIsView,
        public readonly ?string $footerFile,
        public readonly bool $footerFileIsView,
        public readonly ?string $aiNotice = null,
    ) {}
}
