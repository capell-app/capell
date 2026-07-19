<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestBundleAction;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;

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
