<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityAdvisoryData;
use Capell\Core\Data\Security\SecurityInventoryPackageData;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Support\Security\Checks\AutoUpdatesOffForAdvisoryCheck;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\Checks\InsecureCookieCheck;
use Capell\Core\Support\Security\Checks\InstallerEnabledCheck;
use Capell\Core\Support\Security\Checks\KeyMissingCheck;
use Capell\Core\Support\Security\Checks\SecurityAdvisoryCheck;
use Capell\Core\Support\Security\Checks\UrlNotHttpsCheck;
use Carbon\CarbonImmutable;

function postureInventory(array $flags = [], array $packages = [], array $advisories = []): SecurityPostureInventoryData
{
    return new SecurityPostureInventoryData(null, $packages, $flags, $advisories, CarbonImmutable::now());
}

it('detects posture weaknesses only when their conditions hold', function (string $class, array $bad, array $good, SecurityWeaknessSeverity $severity): void {
    /** @var class-string<SecurityWeaknessCheck> $class */
    $check = new $class;
    $findings = $check->detect(postureInventory($bad));
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe($severity)
        ->and($check->detect(postureInventory($good)))->toBeEmpty();
})->with([
    [DebugEnabledCheck::class, ['app.debug' => true, 'app.env' => 'production'], ['app.debug' => false], SecurityWeaknessSeverity::Critical],
    [DebugEnabledCheck::class, ['app.debug' => true, 'app.env' => 'local'], ['app.debug' => false], SecurityWeaknessSeverity::High],
    [UrlNotHttpsCheck::class, ['app.env' => 'production', 'app.url_scheme' => 'http'], ['app.env' => 'production', 'app.url_scheme' => 'https'], SecurityWeaknessSeverity::High],
    [KeyMissingCheck::class, ['app.key_present' => false], ['app.key_present' => true], SecurityWeaknessSeverity::Critical],
    [InsecureCookieCheck::class, ['app.url_scheme' => 'https', 'session.secure_cookie' => false, 'session.http_only' => true], ['app.url_scheme' => 'https', 'session.secure_cookie' => true, 'session.http_only' => true], SecurityWeaknessSeverity::High],
    [InsecureCookieCheck::class, ['app.url_scheme' => 'http', 'session.http_only' => false], ['app.url_scheme' => 'http', 'session.http_only' => true], SecurityWeaknessSeverity::High],
    [InstallerEnabledCheck::class, ['installer.enabled' => true, 'installation.complete' => true], ['installer.enabled' => true, 'installation.complete' => false], SecurityWeaknessSeverity::High],
]);

it('matches advisories to packages and deduplicates policy findings', function (): void {
    $advisories = [new SecurityAdvisoryData('CVE-1', 'example/package', SecurityWeaknessSeverity::High, 'Advisory'), new SecurityAdvisoryData('CVE-2', 'example/package', SecurityWeaknessSeverity::Medium, 'Advisory')];
    $inventory = postureInventory([], [new SecurityInventoryPackageData('example/package', '1.0', autoUpdatePolicy: 'none')], $advisories);
    expect((new SecurityAdvisoryCheck)->detect($inventory))->toHaveCount(2)
        ->and((new AutoUpdatesOffForAdvisoryCheck)->detect($inventory))->toHaveCount(1)
        ->and((new AutoUpdatesOffForAdvisoryCheck)->detect(postureInventory([], [new SecurityInventoryPackageData('example/package', '1.0', autoUpdatePolicy: 'security')], $advisories)))->toBeEmpty()
        ->and((new SecurityAdvisoryCheck)->detect(postureInventory()))->toBeEmpty();
});
