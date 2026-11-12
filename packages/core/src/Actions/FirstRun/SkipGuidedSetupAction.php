<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Records that the person chose to set the site up themselves, so guided setup stops redirecting them. */
final class SkipGuidedSetupAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site): void
    {
        $site->forceFill(['meta' => [
            ...($site->meta ?? []),
            'guided_setup' => [...(array) data_get($site->meta, 'guided_setup', []), 'skipped_at' => now()->toIso8601String()],
        ]])->save();
    }
}
