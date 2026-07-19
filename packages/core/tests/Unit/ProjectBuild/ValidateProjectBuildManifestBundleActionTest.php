<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ProjectBuild\ReadProjectBuildManifestAction;
use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestBundleAction;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildManifestMigration;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestMigrationRegistry;

final class BundleVersionZeroProjectBuildManifestMigration implements ProjectBuildManifestMigration
{
    public function fromVersion(): int
    {
        return 0;
    }

    public function toVersion(): int
    {
        return 1;
    }

    public function migrate(array $payload): array
    {
        $payload['schemaVersion'] = 1;
        unset($payload['legacyVersion']);

        return $payload;
    }
}

function projectBuildFixturePath(string $path): string
{
    return dirname(__DIR__, 2) . '/fixtures/project-build/' . $path;
}

it('enforces read signature and artifact validation in one fail-closed operation', function (): void {
    $manifestJson = file_get_contents(projectBuildFixturePath('one-site-one-locale.json'));
    $publicKey = base64_decode(trim((string) file_get_contents(projectBuildFixturePath('signing-public-key.txt'))), true);
    expect($manifestJson)->toBeString()
        ->and($publicKey)->toBeString();
    assert(is_string($manifestJson));
    assert(is_string($publicKey));

    $manifest = resolve(ValidateProjectBuildManifestBundleAction::class)->handle(
        $manifestJson,
        $publicKey,
        static fn (ProjectBuildArtifactReferenceData $artifact): string => (string) file_get_contents(projectBuildFixturePath($artifact->path)),
    );

    expect($manifest->buildId)->toBe('019f7bf4-45b4-70f1-b8c9-f88d8c783b41');
});

it('does not read artifacts before the manifest signature is verified', function (): void {
    $payload = json_decode((string) file_get_contents(projectBuildFixturePath('one-site-one-locale.json')), true, 512, JSON_THROW_ON_ERROR);
    $payload['buildId'] = '019f7bf4-45b4-70f1-b8c9-f88d8c783b99';
    $publicKey = base64_decode(trim((string) file_get_contents(projectBuildFixturePath('signing-public-key.txt'))), true);
    assert(is_string($publicKey));
    $artifactReads = 0;

    expect(function () use ($payload, $publicKey, &$artifactReads): void {
        resolve(ValidateProjectBuildManifestBundleAction::class)->handle(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $publicKey,
            static function (ProjectBuildArtifactReferenceData $artifact) use (&$artifactReads): string {
                $artifactReads++;

                return '';
            },
        );
    })->toThrow(RuntimeException::class, 'could not be verified')
        ->and($artifactReads)->toBe(0);
});

it('refuses artifact bytes that do not match the signed reference', function (): void {
    $manifestJson = file_get_contents(projectBuildFixturePath('one-site-one-locale.json'));
    $publicKey = base64_decode(trim((string) file_get_contents(projectBuildFixturePath('signing-public-key.txt'))), true);
    assert(is_string($manifestJson));
    assert(is_string($publicKey));

    expect(function () use ($manifestJson, $publicKey): void {
        resolve(ValidateProjectBuildManifestBundleAction::class)->handle(
            $manifestJson,
            $publicKey,
            static fn (ProjectBuildArtifactReferenceData $artifact): string => 'tampered-bytes',
        );
    })->toThrow(RuntimeException::class, 'size does not match');
});

it('verifies legacy signed bytes before applying a trusted core migration', function (): void {
    $payload = json_decode((string) file_get_contents(projectBuildFixturePath('one-site-one-locale.json')), true, 512, JSON_THROW_ON_ERROR);
    $payload['schemaVersion'] = 0;
    $payload['legacyVersion'] = 'v0';
    $seed = hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');
    expect($seed)->toBeString();
    assert(is_string($seed));
    $keyPair = sodium_crypto_sign_seed_keypair($seed);
    $payload['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
        CanonicalizeProjectBuildManifestSigningInputAction::run($payload),
        sodium_crypto_sign_secretkey($keyPair),
    ));
    $migrations = new ProjectBuildManifestMigrationRegistry;
    $migrations->register(new BundleVersionZeroProjectBuildManifestMigration);
    $action = new ValidateProjectBuildManifestBundleAction(
        new ReadProjectBuildManifestAction($migrations),
        resolve(ProjectBuildArtifactHandlerRegistry::class),
    );

    $manifest = $action->handle(
        json_encode($payload, JSON_THROW_ON_ERROR),
        sodium_crypto_sign_publickey($keyPair),
        static fn (ProjectBuildArtifactReferenceData $artifact): string => (string) file_get_contents(projectBuildFixturePath($artifact->path)),
    );

    expect($manifest->schemaVersion)->toBe(1);
});
