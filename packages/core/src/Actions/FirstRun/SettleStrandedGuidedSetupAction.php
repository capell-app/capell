<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Settles a site whose guided setup created pages before setup kept the spec it built: its Publish
 * cannot run, so it is never offered. The pages stay as they are, for the person to publish from the
 * editor. Returns whether this call settled the site, so the person is told once.
 */
final class SettleStrandedGuidedSetupAction
{
    use AsFake;
    use AsObject;

    public const string REASON_NO_STORED_SPEC = 'no_stored_spec';

    public function handle(Site $site): bool
    {
        $settled = DB::transaction(function () use ($site): bool {
            $locked = Site::query()->lockForUpdate()->whereKey($site->getKey())->first();

            if (! $locked instanceof Site || ! ResolveGuidedSetupStateAction::isStranded($locked)) {
                return false;
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            data_set($meta, 'guided_setup.settled_at', now()->toIso8601String());
            data_set($meta, 'guided_setup.settled_reason', self::REASON_NO_STORED_SPEC);
            $locked->forceFill(['meta' => $meta])->save();

            return true;
        });

        resolve(GuidedSetupStateMemo::class)->flush();

        return $settled;
    }
}
