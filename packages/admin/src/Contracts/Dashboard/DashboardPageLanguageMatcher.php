<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;

/** @internal */
interface DashboardPageLanguageMatcher
{
    /**
     * Match an already site- and policy-authorised page's effective language.
     * Workflow implementations must retain draft identity and honour inherited
     * translations, workspace overrides and deletion tombstones.
     */
    public function matches(Authenticatable $actor, Page $page, string $language): bool;
}
