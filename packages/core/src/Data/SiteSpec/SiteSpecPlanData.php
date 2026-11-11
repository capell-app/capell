<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Capell\Core\Enums\SiteSpecPlanAction;
use Spatie\LaravelData\Data;

/** What applying a SiteSpec to a site would change, worked out without writing anything. */
final class SiteSpecPlanData extends Data
{
    /**
     * @param  list<SiteSpecPlanItemData>  $items
     * @param  list<string>  $appliers  Package-owned parts (such as navigation) that apply their own changes and cannot be previewed here.
     * @param  bool  $packagePartsHeld  Whether package-owned parts and media are left out because a private spec would otherwise change live pages.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $appliers = [],
        public readonly bool $packagePartsHeld = false,
    ) {}

    public function count(SiteSpecPlanAction $action): int
    {
        return count(array_filter($this->items, static fn (SiteSpecPlanItemData $item): bool => $item->action === $action));
    }

    /** Whether applying the spec would write anything. */
    public function hasChanges(): bool
    {
        return $this->count(SiteSpecPlanAction::Create) + $this->count(SiteSpecPlanAction::Update) > 0;
    }
}
