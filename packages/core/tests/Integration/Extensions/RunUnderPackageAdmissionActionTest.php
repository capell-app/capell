<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\RunUnderPackageAdmissionAction;
use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Illuminate\Support\Facades\DB;

const ADMISSION_PACKAGE = 'capell-test/admission-fixture';

beforeEach(function (): void {
    CapellCore::registerPackage(ADMISSION_PACKAGE);
    CapellCore::markPackageInstalled(ADMISSION_PACKAGE);
});

function admissionRuns(): array
{
    $runs = 0;
    $result = RunUnderPackageAdmissionAction::run(ADMISSION_PACKAGE, function () use (&$runs): string {
        $runs++;

        return 'done';
    });

    return [$result, $runs];
}

it('runs work while the package is enabled and returns its value', function (): void {
    [$result, $runs] = admissionRuns();

    expect($result->admitted)->toBeTrue()
        ->and($result->value)->toBe('done')
        ->and($runs)->toBe(1);
});

it('refuses work once another process disables the package, although this process still reads it as enabled', function (): void {
    expect(CapellCore::isPackageEnabled(ADMISSION_PACKAGE))->toBeTrue();

    // A direct write models another process: this process's lifecycle cache is not cleared.
    DB::table((new CapellExtension)->getTable())->where('composer_name', ADMISSION_PACKAGE)->update(['status' => ExtensionStatusEnum::Disabled->value]);

    [$result, $runs] = admissionRuns();

    expect(CapellCore::isPackageEnabled(ADMISSION_PACKAGE))->toBeTrue()
        ->and($result->admitted)->toBeFalse()
        ->and($result->reason)->toBe('disabled')
        ->and($runs)->toBe(0);
});

it('refuses work for a quarantined, uninstalled, missing or unknown package', function (Closure $arrange, string $reason, string $package): void {
    $arrange();
    $runs = 0;

    $result = RunUnderPackageAdmissionAction::run($package, function () use (&$runs): void {
        $runs++;
    });

    expect($result->admitted)->toBeFalse()
        ->and($result->reason)->toBe($reason)
        ->and($runs)->toBe(0);
})->with([
    'quarantined' => [fn () => CapellExtension::query()->where('composer_name', ADMISSION_PACKAGE)->update(['provider_recovery_state' => ExtensionProviderRecoveryStateEnum::Quarantined]), 'quarantined', ADMISSION_PACKAGE],
    'uninstalled' => [fn () => CapellCore::markPackageUninstalled(ADMISSION_PACKAGE), 'unavailable', ADMISSION_PACKAGE],
    'missing record' => [fn () => CapellExtension::query()->where('composer_name', ADMISSION_PACKAGE)->delete(), 'unavailable', ADMISSION_PACKAGE],
    'unknown package' => [fn (): null => null, 'unknown-package', 'capell-test/not-registered'],
]);

it('admits work again after re-enable, exactly once per call', function (): void {
    CapellCore::markPackageDisabled(ADMISSION_PACKAGE);
    expect(admissionRuns()[1])->toBe(0);

    CapellCore::markPackageInstalled(ADMISSION_PACKAGE);
    [$result, $runs] = admissionRuns();

    expect($result->admitted)->toBeTrue()->and($runs)->toBe(1);
});

it('rolls admitted writes back when the work fails', function (): void {
    $before = CapellExtension::query()->where('composer_name', ADMISSION_PACKAGE)->value('description');

    expect(fn () => RunUnderPackageAdmissionAction::run(ADMISSION_PACKAGE, function (): never {
        CapellExtension::query()->where('composer_name', ADMISSION_PACKAGE)->update(['description' => 'written by failed work']);

        throw new RuntimeException('work failed');
    }))->toThrow(RuntimeException::class, 'work failed');

    expect(CapellExtension::query()->where('composer_name', ADMISSION_PACKAGE)->value('description'))->toBe($before);
});
