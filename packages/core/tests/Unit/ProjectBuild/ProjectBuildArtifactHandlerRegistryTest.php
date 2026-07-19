<?php

declare(strict_types=1);

use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;

final class RecordingProjectBuildArtifactHandler implements ProjectBuildArtifactHandler
{
    public int $calls = 0;

    public function type(): string
    {
        return 'capell-theme';
    }

    public function validate(ProjectBuildArtifactReferenceData $artifact, string $bytes): void
    {
        $this->calls++;
    }
}

function projectBuildArtifactReference(string $bytes = 'theme-bytes'): ProjectBuildArtifactReferenceData
{
    return new ProjectBuildArtifactReferenceData(
        key: 'theme',
        type: 'capell-theme',
        path: 'artifacts/theme.zip',
        digest: hash('sha256', $bytes),
        sizeBytes: strlen($bytes),
        mediaType: 'application/zip',
    );
}

it('discovers handlers and dispatches only integrity-verified bytes', function (): void {
    $handler = new RecordingProjectBuildArtifactHandler;
    app()->instance(RecordingProjectBuildArtifactHandler::class, $handler);
    app()->tag([RecordingProjectBuildArtifactHandler::class], ProjectBuildArtifactHandler::TAG);
    $registry = new ProjectBuildArtifactHandlerRegistry(app());

    $registry->validate(projectBuildArtifactReference(), 'theme-bytes');

    expect(ProjectBuildArtifactHandler::TAG)->toBe('capell.project-build.artifact-handler')
        ->and($registry->types())->toBe(['capell-theme'])
        ->and($handler->calls)->toBe(1);
});

it('registers the artifact registry as a core singleton', function (): void {
    expect(resolve(ProjectBuildArtifactHandlerRegistry::class))
        ->toBe(resolve(ProjectBuildArtifactHandlerRegistry::class));
});

it('rejects duplicate handler types', function (): void {
    $registry = new ProjectBuildArtifactHandlerRegistry(app());
    $registry->register(new RecordingProjectBuildArtifactHandler);

    expect(function () use ($registry): void {
        $registry->register(new RecordingProjectBuildArtifactHandler);
    })
        ->toThrow(LogicException::class, 'already registered');
});

it('rejects missing handlers and payload integrity mismatches before dispatch', function (string $bytes, string $expectedMessage): void {
    $handler = new RecordingProjectBuildArtifactHandler;
    $registry = new ProjectBuildArtifactHandlerRegistry(app());
    $registry->register($handler);
    $artifact = projectBuildArtifactReference();

    if ($expectedMessage === 'handler') {
        $artifact = new ProjectBuildArtifactReferenceData(
            key: 'media',
            type: 'media',
            path: 'artifacts/media.png',
            digest: hash('sha256', $bytes),
            sizeBytes: strlen($bytes),
            mediaType: 'image/png',
        );
    }

    expect(function () use ($registry, $artifact, $bytes): void {
        $registry->validate($artifact, $bytes);
    })
        ->toThrow(RuntimeException::class, $expectedMessage)
        ->and($handler->calls)->toBe(0);
})->with([
    'size mismatch' => ['short', 'size'],
    'digest mismatch' => ['other-bytes', 'digest'],
    'missing handler' => ['media-bytes', 'handler'],
]);
