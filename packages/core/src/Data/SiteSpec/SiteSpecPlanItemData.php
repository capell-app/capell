<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Capell\Core\Enums\SiteSpecPlanAction;
use Spatie\LaravelData\Data;

/** One thing applying a SiteSpec would do: create, update or leave a page or the theme. */
final class SiteSpecPlanItemData extends Data
{
    public const string NOTE_LIVE_PAGE_HELD = 'live_page_held';

    /** A first install's placeholder home page: kept as it is until the person publishes, without holding anything else back. */
    public const string NOTE_PLACEHOLDER_HOME_KEPT = 'placeholder_home_kept';

    /**
     * @param  string  $kind  What the item is: `page` or `theme`.
     * @param  string  $key  The page slug, or the theme key.
     * @param  list<string>  $changes  The fields an update would change.
     * @param  string|null  $note  Why an item is left alone when the spec differs from it; currently `live_page_held`.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly SiteSpecPlanAction $action,
        public readonly array $changes = [],
        public readonly ?string $note = null,
    ) {}
}
