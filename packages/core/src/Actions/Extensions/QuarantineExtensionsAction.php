<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Data\Extensions\ExtensionQuarantineEvidenceData;
use Capell\Core\Models\ExtensionQuarantine;
use Capell\Core\Support\Packages\ExtensionQuarantineGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Hold every package an operation changed back from enabling and booting,
 * because one of them failed verification. A tampered dependency must not stay
 * loaded beside a verified extension that pulled it in.
 *
 * @method static void run(list<string> $packages, string $failingPackage, ?ExtensionQuarantineEvidenceData $evidence = null)
 */
final class QuarantineExtensionsAction
{
    use AsFake;
    use AsObject;

    /** @param list<string> $packages */
    public function handle(array $packages, string $failingPackage, ?ExtensionQuarantineEvidenceData $evidence = null): void
    {
        $set = array_values(array_unique([...$packages, $failingPackage]));
        sort($set);
        $now = CarbonImmutable::now();

        DB::transaction(static function () use ($set, $failingPackage, $now, $evidence): void {
            foreach ($set as $package) {
                ExtensionQuarantine::query()->updateOrCreate(
                    ['composer_name' => $package],
                    [
                        'failing_package' => $failingPackage,
                        'quarantined_set' => $set,
                        'failing_comparison' => $evidence?->comparison,
                        'expected_digest' => $evidence?->expectedDigest,
                        'actual_digest' => $evidence?->actualDigest,
                        'quarantined_at' => $now,
                    ],
                );
            }
        });

        resolve(ExtensionQuarantineGate::class)->forget();
    }
}
