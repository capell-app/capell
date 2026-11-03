<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Models\ExtensionQuarantine;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Remove quarantine records once the quarantined code is gone, for example
 * after a rollback has restored the previous Composer state.
 *
 * @method static int run(list<string> $packages)
 */
final class LiftExtensionQuarantineAction
{
    use AsFake;
    use AsObject;

    /** @param list<string> $packages */
    public function handle(array $packages): int
    {
        if ($packages === []) {
            return 0;
        }

        return (int) ExtensionQuarantine::query()->whereIn('composer_name', $packages)->delete();
    }
}
