<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

/** One field a SiteSpec would change, with the value the site has now and the value the spec would write. */
final class SiteSpecPlanChangeData extends Data
{
    /**
     * @param  string  $field  The field, such as `title`, `content` or a theme setting like `colors.primary`.
     * @param  string|null  $before  The current value; null when the field has no value yet.
     * @param  string|null  $after  The value applying the spec would write.
     */
    public function __construct(
        public readonly string $field,
        public readonly ?string $before,
        public readonly ?string $after,
    ) {}
}
