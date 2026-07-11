<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\CaptureLayoutContentSnapshotAction;
use Capell\Core\Actions\GenerateUniqueKeyAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Support\CapellCoreHelper;

class LayoutObserver
{
    public function saving(Layout $layout): void
    {
        $key = $layout->getAttribute('key');

        if (! is_string($key) || $key === '') {
            $layout->key = GenerateUniqueKeyAction::run($layout);
        }
    }

    public function saved(Layout $layout): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::RelationExists,
            CacheEnum::FirstPageByTypeForSite,
        ]);

        event(new FrontendSurrogateKeysInvalidated($this->surrogateKeysForLayout($layout)));
    }

    public function deleting(Layout $layout): void
    {
        if ($layout->isForceDeleting()) {
            return;
        }

        CaptureLayoutContentSnapshotAction::run($layout, 'layout_delete');
    }

    public function deleted(Layout $layout): void
    {
        $this->saved($layout);
    }

    public function restored(Layout $layout): void
    {
        $this->saved($layout);
    }

    /**
     * @return list<string>
     */
    private function surrogateKeysForLayout(Layout $layout): array
    {
        if ($layout->site_id !== null) {
            return ['site-' . $layout->site_id];
        }

        return array_values(Site::query()
            ->pluck('id')
            ->map(fn (int $siteId): string => 'site-' . $siteId)
            ->all());
    }
}
