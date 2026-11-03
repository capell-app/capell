<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Models\ExtensionQuarantine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Hold every package an operation changed back from enabling and booting,
 * because one of them failed verification. A tampered dependency must not stay
 * loaded beside a verified extension that pulled it in.
 *
 * @method static void run(list<string> $packages, string $failingPackage)
 */
final class QuarantineExtensionsAction
{
    use AsFake;
    use AsObject;

    /** @param list<string> $packages */
    public function handle(array $packages, string $failingPackage): void
    {
        $set = array_values(array_unique([...$packages, $failingPackage]));
        sort($set);
        $now = CarbonImmutable::now();

        DB::transaction(static function () use ($set, $failingPackage, $now): void {
            foreach ($set as $package) {
                ExtensionQuarantine::query()->updateOrCreate(
                    ['composer_name' => $package],
                    ['failing_package' => $failingPackage, 'quarantined_set' => $set, 'quarantined_at' => $now],
                );
            }
        });
    }
}
