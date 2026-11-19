<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;

/**
 * The page, site, language, layout and theme a static artifact was rendered
 * from, as types and keys. Internal export metadata: it is fingerprinted into
 * the artifact manifest and never rendered or stored in public render data.
 */
final readonly class StaticPageArtifactSourceData
{
    /** @param class-string|null $pageType */
    public function __construct(
        public ?string $pageType = null,
        public ?int $pageId = null,
        public ?int $siteId = null,
        public ?int $languageId = null,
        public ?int $layoutId = null,
        public ?int $themeId = null,
    ) {}

    public static function fromModels(?Pageable $page, ?Site $site, ?Language $language, ?Layout $layout, ?Theme $theme): self
    {
        $pageModel = $page instanceof Model ? $page : null;

        return new self(
            pageType: $pageModel instanceof Model ? $pageModel::class : null,
            pageId: self::key($pageModel),
            siteId: self::key($site),
            languageId: self::key($language),
            layoutId: self::key($layout),
            themeId: self::key($theme),
        );
    }

    private static function key(?Model $model): ?int
    {
        $key = $model?->getKey();

        return is_numeric($key) ? (int) $key : null;
    }
}
