<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Composer\InstalledVersions;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveInstalledComposerVersionsAction
{
    use AsAction;

    /**
     * @param  array<int, string>  $prefixes
     * @return array<string, string>
     */
    public function handle(array $prefixes = ['capell-app/']): array
    {
        $versions = [];

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            foreach ($prefixes as $prefix) {
                if (! str_starts_with($package, $prefix)) {
                    continue;
                }

                $pretty = InstalledVersions::getPrettyVersion($package);
                if ($pretty !== null) {
                    $versions[$package] = $pretty;
                }

                break;
            }
        }

        ksort($versions);

        return $versions;
    }
}
