<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Security\Remediations\AutoUpdatesForAdvisoryRemediation;
use Capell\Core\Support\Security\Remediations\DebugEnabledRemediation;
use Capell\Core\Support\Security\Remediations\EnvironmentRemediation;
use Capell\Core\Support\Security\Remediations\InsecureCookieRemediation;
use Illuminate\Support\Facades\File;

function remediationFinding(?string $composerName = null): SecurityWeaknessFindingData
{
    return new SecurityWeaknessFindingData('test.example', 'fingerprint', SecurityWeaknessSeverity::High, SecurityFindingSource::Builtin, 'Title', 'Summary', composerName: $composerName);
}

it('changes only the intended environment settings and is idempotent', function (string $class, string $translation, array $before, array $after): void {
    $directory = sys_get_temp_dir() . '/capell-remediation-' . uniqid();
    File::ensureDirectoryExists($directory);
    $originalBase = app()->basePath();
    $originalStorage = app()->storagePath();
    $original = "# comment\nAPP_DEBUG=true\nOTHER=\"a # b\"\nSESSION_SECURE_COOKIE=false\nSESSION_HTTP_ONLY=false\nLAST=value\n";
    file_put_contents($directory . '/.env', $original);
    app()->setBasePath($directory);
    app()->useStoragePath($directory . '/storage');
    config(['app.url' => 'https://example.test']);
    try {
        /** @var class-string<SecurityWeaknessRemediation> $class */
        $remediation = new $class(new EnvironmentRemediation);
        expect($remediation->isReversible())->toBeTrue()
            ->and($remediation->describe())->toBe(__($translation));
        $result = $remediation->apply(remediationFinding());
        $expected = $original;
        foreach ($after as $key => $value) {
            $expected = str_replace($key . '=' . $before[$key], $key . '=' . $value, $expected);
        }

        expect($result->applied)->toBeTrue()
            ->and($result->before)->toEqual($before)
            ->and(array_intersect_key($result->after, $after))->toEqual($after)
            ->and($result->after)->toHaveKey('backup_directory')
            ->and(file_get_contents($directory . '/.env'))->toBe($expected);
        expect($remediation->apply(remediationFinding())->applied)->toBeFalse()
            ->and(file_get_contents($directory . '/.env'))->toBe($expected);
    } finally {
        app()->setBasePath($originalBase);
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($directory);
    }
})->with([
    [DebugEnabledRemediation::class, 'capell-core::security.remediation.debug', ['APP_DEBUG' => 'true'], ['APP_DEBUG' => 'false']],
    [InsecureCookieRemediation::class, 'capell-core::security.remediation.cookie', ['SESSION_SECURE_COOKIE' => 'false', 'SESSION_HTTP_ONLY' => 'false'], ['SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true']],
]);

it('enables security updates once and describes the reversible change', function (): void {
    $extension = CapellExtension::query()->create(['composer_name' => 'example/security', 'name' => 'Security', 'status' => ExtensionStatusEnum::Enabled, 'auto_update_policy' => ExtensionAutoUpdatePolicyEnum::None]);
    $remediation = new AutoUpdatesForAdvisoryRemediation;
    $finding = remediationFinding('example/security');
    $result = $remediation->apply($finding);
    expect($remediation->isReversible())->toBeTrue()
        ->and($remediation->describe())->toBe(__('capell-core::security.remediation.updates'))
        ->and($result->applied)->toBeTrue()
        ->and($result->before)->toEqual(['auto_update_policy' => 'none'])
        ->and($result->after)->toEqual(['auto_update_policy' => 'security'])
        ->and($extension->refresh()->auto_update_policy)->toBe(ExtensionAutoUpdatePolicyEnum::Security)
        ->and($remediation->apply($finding)->applied)->toBeFalse();
});
