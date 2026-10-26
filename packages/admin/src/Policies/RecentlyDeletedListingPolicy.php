<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use WeakMap;

/** A single listing batch; never register this policy on the application Gate. */
final readonly class RecentlyDeletedListingPolicy
{
    /** @param WeakMap<Model, bool> $decisions */
    private function __construct(
        private User $actor,
        private WeakMap $decisions,
    ) {}

    /**
     * Only use with the unmodified built-in policies and records freshly read
     * through SiteAccess. That query already checks persisted Media ownership.
     *
     * @param  Collection<int, Model>  $records
     */
    public static function forScopedRecords(User $actor, Collection $records, MediaPolicy|PagePolicy $policy): self
    {
        $decisions = new WeakMap;
        $equivalent = [];
        foreach ($records as $record) {
            if ($record instanceof Media && $policy::class === MediaPolicy::class) {
                $decisions[$record] = $equivalent['media'] ??= $policy->viewAny($actor);
            } elseif ($record instanceof Page && $policy::class === PagePolicy::class) {
                // The standard Page view policy depends on site, blueprint
                // restrictions and the actor, rather than individual page IDs.
                $key = $record->site_id . ':' . $record->blueprint_id;
                $decisions[$record] = $equivalent[$key] ??= $policy->view($actor, $record);
            }
        }

        return new self($actor, $decisions);
    }

    public function view(User $user, Model $record): bool
    {
        return $user === $this->actor && ($this->decisions[$record] ?? false);
    }
}
