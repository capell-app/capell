<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Redirects;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\RedirectHealthSnapshot;
use Illuminate\Database\Eloquent\Model;

final class RedirectHealthRequestCache
{
    /** @var array<int, object|null> */
    private array $snapshots = [];

    public function for(PageUrl $pageUrl): ?object
    {
        if (! array_key_exists($pageUrl->id, $this->snapshots)) {
            $this->snapshots[$pageUrl->id] = $this->resolve($pageUrl);
        }

        return $this->snapshots[$pageUrl->id];
    }

    private function resolve(PageUrl $pageUrl): ?object
    {
        if ($pageUrl->relationLoaded('redirectHealthSnapshot')) {
            return $pageUrl->redirectHealthSnapshot;
        }

        /** @var class-string<Model> $snapshotClass */
        $snapshotClass = RedirectHealthSnapshot::class;

        return $snapshotClass::query()
            ->where('page_url_id', $pageUrl->id)
            ->first();
    }
}
