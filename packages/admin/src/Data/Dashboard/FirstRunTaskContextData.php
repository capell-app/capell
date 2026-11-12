<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;

/** The site the getting-started checklist is about, for task contributors. */
final class FirstRunTaskContextData extends Data
{
    public function __construct(
        public readonly Authenticatable $actor,
        public readonly int $siteId,
        public readonly int $homePageId,
    ) {}
}
