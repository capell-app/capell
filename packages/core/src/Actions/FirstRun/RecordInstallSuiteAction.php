<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Remembers which install suite a site started from, so guided setup can preselect the matching preset. */
final class RecordInstallSuiteAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $suiteKey): void
    {
        $suiteKey = trim((string) $suiteKey);

        if ($suiteKey === '') {
            return;
        }

        foreach (Site::query()->get() as $site) {
            if (filled(data_get($site->meta, 'install_suite'))) {
                continue;
            }

            $site->forceFill(['meta' => [...($site->meta ?? []), 'install_suite' => $suiteKey]])->save();
        }
    }
}
