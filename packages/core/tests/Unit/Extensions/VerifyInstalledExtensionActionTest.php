<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;

it('supports action fakes and forwards all arguments', function (): void {
    $report = new ExtensionVerificationReportData('vendor/example', '2.0.0', '^2', '1.0.0', true, [], [], CarbonImmutable::now());
    VerifyInstalledExtensionAction::shouldRun()->once()->with('vendor/example', '^2', '1.0.0', 'example', 'abc')->andReturn($report);
    expect(VerifyInstalledExtensionAction::run('vendor/example', '^2', '1.0.0', 'example', 'abc'))->toBe($report);
    VerifyInstalledExtensionAction::clearFake();
});

it('uses the application vendor directory for verification', function (): void {
    $original = base_path();
    $root = sys_get_temp_dir() . '/capell-verifier-action-' . bin2hex(random_bytes(8));
    mkdir($root . '/vendor/composer', 0777, true);
    file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'vendor/action-fixture', 'version' => '4.0.0', 'install-path' => '../vendor/action-fixture',
    ]]], JSON_THROW_ON_ERROR));
    try {
        app()->setBasePath($root);
        $report = VerifyInstalledExtensionAction::run('vendor/action-fixture', '^4');
        expect($report->installedVersion)->toBe('4.0.0')->and($report->passed)->toBeFalse()
            ->and(array_column($report->failures, 'code'))->toContain('manifest_missing')->not->toContain('not_installed')->not->toContain('version_outside_constraint');
    } finally {
        app()->setBasePath($original);
        new Filesystem()->deleteDirectory($root);
    }
});
