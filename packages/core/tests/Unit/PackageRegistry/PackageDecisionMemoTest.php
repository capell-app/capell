<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

function memoPackageName(): string
{
    return 'vendor/decision-memo-' . bin2hex(random_bytes(4));
}

it('authorises a package once however many times a request asks whether it is enabled', function (): void {
    $name = memoPackageName();
    CapellCore::registerPackage($name);
    $authorisations = 0;
    app()->beforeResolving(ExtensionCommercialPolicy::class, function () use (&$authorisations): void {
        $authorisations++;
    });

    for ($lookup = 0; $lookup < 50; $lookup++) {
        CapellCore::isPackageEnabled($name);
        CapellCore::isPackageInstalled($name);
    }

    expect($authorisations)->toBe(1);
});

it('resolves the runtime lifecycle once however many times a request asks about a package', function (): void {
    $name = memoPackageName();
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    CapellCore::isPackageEnabled($name);
    $resolves = 0;
    app()->beforeResolving(InstalledRuntimeLifecycle::class, function () use (&$resolves): void {
        $resolves++;
    });

    for ($lookup = 0; $lookup < 50; $lookup++) {
        CapellCore::isPackageEnabled($name);
        CapellCore::isPackageInstalled($name);
    }

    expect($resolves)->toBeLessThanOrEqual(1);
});

it('denies a package as soon as its runtime fails after an enabled answer', function (): void {
    $name = memoPackageName();
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    expect(CapellCore::isPackageEnabled($name))->toBeTrue()
        ->and(CapellCore::isPackageInstalled($name))->toBeTrue();

    resolve(InstalledRuntimeLifecycle::class)->recordFailure(new RuntimeException('boot failed'), $name, 'Vendor\\Provider', 'runtime', 'boot');

    expect(CapellCore::isPackageEnabled($name))->toBeFalse()
        ->and(CapellCore::isPackageInstalled($name))->toBeFalse();
});

it('forgets an enabled answer when the package is disabled or forced off', function (): void {
    $disabled = memoPackageName();
    CapellCore::registerPackage($disabled);
    CapellCore::markPackageInstalled($disabled);
    expect(CapellCore::isPackageEnabled($disabled))->toBeTrue();

    CapellCore::markPackageDisabled($disabled);

    expect(CapellCore::isPackageEnabled($disabled))->toBeFalse();

    $forced = memoPackageName();
    CapellCore::registerPackage($forced);
    CapellCore::forcePackageInstalled($forced);
    expect(CapellCore::isPackageInstalled($forced))->toBeTrue();

    CapellCore::forcePackageInstalled($forced, false);

    expect(CapellCore::isPackageInstalled($forced))->toBeFalse();
});

it('asks again after a lifecycle read failed instead of keeping an answer made without the record', function (): void {
    $preloaded = memoPackageName();
    CapellCore::registerPackage($preloaded);
    CapellCore::markPackageInstalled($preloaded);
    expect(CapellCore::isPackageEnabled($preloaded))->toBeTrue();

    // Registered after the lifecycle records were preloaded, so its record is read on its own.
    $name = memoPackageName();
    CapellCore::registerPackage($name);
    DB::table('capell_extensions')->insert([
        'composer_name' => $name,
        'status' => ExtensionStatusEnum::Disabled->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $failReads = true;
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$failReads, &$reads, $name): void {
        if (! str_contains($query->sql, 'capell_extensions') || ! in_array($name, $query->bindings, true)) {
            return;
        }

        $reads++;
        throw_if($failReads, RuntimeException::class, 'connection lost');
    });

    CapellCore::isPackageEnabled($name);
    $failReads = false;
    $reads = 0;

    expect(CapellCore::isPackageEnabled($name))->toBeFalse()
        ->and($reads)->toBeGreaterThan(0);
});

it('re-reads a lifecycle change another worker stored once the runtime state is flushed', function (): void {
    $name = memoPackageName();
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    expect(CapellCore::isPackageEnabled($name))->toBeTrue();

    DB::table('capell_extensions')->where('composer_name', $name)->update(['status' => ExtensionStatusEnum::Disabled->value]);
    CapellCore::flushOctaneState();

    expect(CapellCore::isPackageEnabled($name))->toBeFalse();
});
