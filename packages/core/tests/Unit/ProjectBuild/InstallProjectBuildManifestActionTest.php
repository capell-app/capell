<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ProjectBuild\InstallProjectBuildManifestAction;
use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildTargetCompatibilityAction;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildPackageInstaller;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Data\ProjectBuild\ProjectBuildCompatibilityData;
use Capell\Core\Data\ProjectBuild\ProjectBuildInstalledPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildPackageData;
use Capell\Core\Models\Site;

class RecordingProjectBuildPackageInstaller implements ProjectBuildPackageInstaller
{
    /** @var array<string, ProjectBuildInstalledPackageData> */
    public array $installed = [];

    /** @var list<string> */
    public array $installCalls = [];

    public function installedRelease(string $package): ?ProjectBuildInstalledPackageData
    {
        return $this->installed[$package] ?? null;
    }

    public function install(ProjectBuildPackageData $package): ProjectBuildInstalledPackageData
    {
        $this->installCalls[] = $package->name;

        return $this->installed[$package->name] = new ProjectBuildInstalledPackageData(
            name: $package->name,
            version: $package->version,
            releaseIdentity: $package->releaseIdentity,
        );
    }
}

function installProjectBuildFixturePath(string $path): string
{
    return dirname(__DIR__, 2) . '/fixtures/project-build/' . $path;
}

/** @return array{string, string} */
function installProjectBuildFixture(): array
{
    $manifest = file_get_contents(installProjectBuildFixturePath('one-site-one-locale.json'));
    $publicKey = base64_decode(trim((string) file_get_contents(installProjectBuildFixturePath('signing-public-key.txt'))), true);
    expect($manifest)->toBeString()
        ->and($publicKey)->toBeString();
    assert(is_string($manifest));
    assert(is_string($publicKey));

    return [$manifest, $publicKey];
}

/** @param array<string, mixed> $payload */
function signInstallProjectBuildFixture(array $payload): array
{
    $seed = hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');
    assert(is_string($seed));
    $keyPair = sodium_crypto_sign_seed_keypair($seed);
    $payload['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
        CanonicalizeProjectBuildManifestSigningInputAction::run($payload),
        sodium_crypto_sign_secretkey($keyPair),
    ));

    return [$payload, sodium_crypto_sign_publickey($keyPair)];
}

it('installs exact manifest packages before applying SiteSpec and skips exact releases on retry', function (): void {
    [$manifestJson] = installProjectBuildFixture();
    $payload = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
    array_unshift($payload['packages'], [
        'name' => 'vendor/early',
        'version' => '2.3.4',
        'releaseIdentity' => str_repeat('f', 40),
        'installOrder' => 5,
    ]);
    [$payload, $publicKey] = signInstallProjectBuildFixture($payload);
    $manifest = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $installer = new RecordingProjectBuildPackageInstaller;
    $installer->installed['capell-app/core'] = new ProjectBuildInstalledPackageData(
        name: 'capell-app/core',
        version: '1.0.14',
        releaseIdentity: str_repeat('d', 40),
    );
    app()->instance(ProjectBuildPackageInstaller::class, $installer);
    $site = new Site;
    $build = bindFakeAction(BuildCapellSiteFromSpecAction::class, $site);
    $artifactReads = 0;

    $readArtifact = static function (ProjectBuildArtifactReferenceData $artifact) use (&$artifactReads): string {
        $artifactReads++;

        return (string) file_get_contents(installProjectBuildFixturePath($artifact->path));
    };

    $first = InstallProjectBuildManifestAction::run($manifest, $publicKey, $readArtifact, 'local');
    $second = InstallProjectBuildManifestAction::run($manifest, $publicKey, $readArtifact, 'local');

    expect($first)->toBe($site)
        ->and($second)->toBe($site)
        ->and($installer->installCalls)->toBe(['vendor/early', 'capell-app/navigation'])
        ->and($artifactReads)->toBe(2)
        ->and($build->called)->toBeTrue()
        ->and(data_get($build->args[0]->toArray(), 'extensions'))->toBe(['capell-app/navigation']);
});

it('refuses an incompatible target before installing packages or applying SiteSpec', function (string $platform, string $coreVersion, string $message): void {
    [$manifest, $publicKey] = installProjectBuildFixture();
    $installer = new RecordingProjectBuildPackageInstaller;
    $installer->installed['capell-app/core'] = new ProjectBuildInstalledPackageData(
        name: 'capell-app/core',
        version: $coreVersion,
        releaseIdentity: str_repeat('d', 40),
    );
    app()->instance(ProjectBuildPackageInstaller::class, $installer);
    $build = bindFakeAction(BuildCapellSiteFromSpecAction::class, new Site);

    expect(fn (): Site => InstallProjectBuildManifestAction::run(
        $manifest,
        $publicKey,
        static fn (ProjectBuildArtifactReferenceData $artifact): string => (string) file_get_contents(installProjectBuildFixturePath($artifact->path)),
        $platform,
    ))->toThrow(RuntimeException::class, $message)
        ->and($installer->installCalls)->toBe([])
        ->and($build->called)->toBeFalse();
})->with([
    'platform' => ['unsupported', '1.0.14', 'platform'],
    'Core version' => ['local', '2.0.0', 'Capell'],
]);

it('refuses package evidence that does not match the signed release', function (): void {
    [$manifest, $publicKey] = installProjectBuildFixture();
    $installer = new class extends RecordingProjectBuildPackageInstaller
    {
        public function install(ProjectBuildPackageData $package): ProjectBuildInstalledPackageData
        {
            $this->installCalls[] = $package->name;

            return new ProjectBuildInstalledPackageData(
                name: $package->name,
                version: $package->version,
                releaseIdentity: str_repeat('e', 40),
            );
        }
    };
    $installer->installed['capell-app/core'] = new ProjectBuildInstalledPackageData('capell-app/core', '1.0.14', str_repeat('d', 40));
    app()->instance(ProjectBuildPackageInstaller::class, $installer);

    expect(fn (): Site => InstallProjectBuildManifestAction::run(
        $manifest,
        $publicKey,
        static fn (ProjectBuildArtifactReferenceData $artifact): string => (string) file_get_contents(installProjectBuildFixturePath($artifact->path)),
        'local',
    ))->toThrow(RuntimeException::class, 'release identity');
});

it('fails closed for malformed compatibility constraints', function (): void {
    $installer = new RecordingProjectBuildPackageInstaller;
    $installer->installed['capell-app/core'] = new ProjectBuildInstalledPackageData(
        'capell-app/core',
        '1.0.14',
        str_repeat('d', 40),
    );
    app()->instance(ProjectBuildPackageInstaller::class, $installer);

    expect(fn (): mixed => VerifyProjectBuildTargetCompatibilityAction::run(
        new ProjectBuildCompatibilityData(capell: 'not a constraint', php: '^8.4', platforms: ['local']),
        'local',
    ))->toThrow(RuntimeException::class, 'Capell constraint');
});
