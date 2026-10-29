<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Illuminate\Contracts\Auth\Authenticatable;

/** @internal */
interface DashboardAttentionContributor
{
    public const string TAG = 'capell.admin.dashboard-attention';

    /**
     * Return only evidence-backed issues permitted for this actor/site/language.
     *
     * @return list<DashboardAttentionItemData>
     */
    public function build(Authenticatable $actor, ?int $siteId, ?string $language): array;
}
