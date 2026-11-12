<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Records that the person chose to set the site up themselves, so guided setup stops redirecting them. */
final class SkipGuidedSetupAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site): void
    {
        // Read the row under a lock so a go-live recorded at the same moment is not overwritten.
        DB::transaction(function () use ($site): void {
            $fresh = Site::query()->lockForUpdate()->whereKey($site->getKey())->firstOrFail();
            $meta = is_array($fresh->meta) ? $fresh->meta : [];
            data_set($meta, 'guided_setup.skipped_at', now()->toIso8601String());
            $fresh->forceFill(['meta' => $meta])->save();
            $site->setRawAttributes($fresh->getAttributes(), true);
        });
    }
}
