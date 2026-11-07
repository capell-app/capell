<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use WeakMap;

/** Authoritative request-local route context, including Livewire middleware replay. */
final class PaletteRequestState
{
    /** @var WeakMap<Authenticatable, array{teamId: ?int}> */
    private WeakMap $teams;

    public function __construct()
    {
        $this->teams = new WeakMap;
    }

    public function recordTeamId(Authenticatable $actor, ?int $teamId): void
    {
        $this->teams[$actor] = ['teamId' => $teamId];
    }

    /** @param Closure(): ?int $resolve */
    public function resolvedTeamId(Authenticatable $actor, Closure $resolve): ?int
    {
        if (! isset($this->teams[$actor])) {
            $this->recordTeamId($actor, $resolve());
        }

        return $this->teams[$actor]['teamId'];
    }
}
