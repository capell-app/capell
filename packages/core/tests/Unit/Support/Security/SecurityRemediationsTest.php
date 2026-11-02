<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Patching\UnsafeEnvironmentFileException;
use Capell\Core\Support\Security\Remediations\AutoUpdatesForAdvisoryRemediation;
use Capell\Core\Support\Security\Remediations\DebugEnabledRemediation;
use Capell\Core\Support\Security\Remediations\EnvironmentRemediation;
use Capell\Core\Support\Security\Remediations\InsecureCookieRemediation;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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

it('refuses ambiguous environment files without writing or backing up', function (string $contents): void {
    $directory = sys_get_temp_dir() . '/capell-remediation-' . uniqid();
    File::ensureDirectoryExists($directory);
    $base = app()->basePath();
    $storage = app()->storagePath();
    file_put_contents($directory . '/.env', $contents);
    app()->setBasePath($directory);
    app()->useStoragePath($directory . '/storage');
    try {
        $outcome = (new EnvironmentRemediation)->apply(['APP_DEBUG' => 'false']);
        expect($outcome->applied)->toBeFalse()->and($outcome->message)->toBe(__('capell-core::security.remediation.env_ambiguous'))
            ->and(file_get_contents($directory . '/.env'))->toBe($contents)
            ->and(is_dir($directory . '/storage'))->toBeFalse();
    } finally {
        app()->setBasePath($base);
        app()->useStoragePath($storage);
        File::deleteDirectory($directory);
    }
})->with(["APP_DEBUG=true\nAPP_DEBUG=false\n", "APP_DEBUG=true\r\n", "OTHER=\"first\nAPP_DEBUG=true\nlast\"\n"]);

it('refuses a symbolic link before creating a backup', function (): void {
    $directory = sys_get_temp_dir() . '/capell-remediation-' . uniqid();
    File::ensureDirectoryExists($directory);
    $base = app()->basePath();
    $storage = app()->storagePath();
    file_put_contents($directory . '/target', "APP_DEBUG=true\n");
    symlink($directory . '/target', $directory . '/.env');
    app()->setBasePath($directory);
    app()->useStoragePath($directory . '/storage');
    try {
        expect(fn (): SecurityRemediationOutcomeData => (new EnvironmentRemediation)->apply(['APP_DEBUG' => 'false']))->toThrow(UnsafeEnvironmentFileException::class)
            ->and(file_get_contents($directory . '/target'))->toBe("APP_DEBUG=true\n")
            ->and(is_dir($directory . '/storage'))->toBeFalse();
    } finally {
        app()->setBasePath($base);
        app()->useStoragePath($storage);
        File::deleteDirectory($directory);
    }
});

it('skips environment changes when the lock cannot be obtained', function (): void {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->with(10, Mockery::type('closure'))->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->with('capell:security:env-remediation', 30)->andReturn($lock);
    expect((new EnvironmentRemediation)->apply(['APP_DEBUG' => 'false'])->message)->toBe(__('capell-core::security.remediation.env_locked'));
});
