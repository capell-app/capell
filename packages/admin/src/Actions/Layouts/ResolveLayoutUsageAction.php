<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Layouts;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveLayoutUsageAction
{
    use AsFake;
    use AsObject;

    public function handle(Layout $layout): int
    {
        $count = 0;

        foreach (CapellCore::getPageVariationModels() as $pageClass) {
            $count += SiteScope::applyForCurrentActor($pageClass::query())
                ->where('layout_id', $layout->getKey())
                ->count();
        }

        return $count;
    }
}
