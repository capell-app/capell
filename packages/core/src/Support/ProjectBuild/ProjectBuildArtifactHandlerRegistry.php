<?php

declare(strict_types=1);

namespace Capell\Core\Support\ProjectBuild;

use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Contracts\Container\Container;
use LogicException;
use RuntimeException;

/** @extends AbstractKeyedRegistry<ProjectBuildArtifactHandler> */
final class ProjectBuildArtifactHandlerRegistry extends AbstractKeyedRegistry
{
    private bool $taggedHandlersDiscovered = false;

    public function __construct(private readonly Container $container) {}

    public function register(ProjectBuildArtifactHandler $handler): void
    {
        $type = $handler->type();

        throw_unless(preg_match('~' . ProjectBuildManifestConstraints::ARTIFACT_TYPE_PATTERN . '~D', $type) === 1, LogicException::class, 'Project build artifact handler types must match the manifest artifact type grammar.');
        throw_if($this->hasItem($type), LogicException::class, sprintf('A project build artifact handler is already registered for [%s].', $type));

        $this->setItem($type, $handler);
    }

    public function validate(ProjectBuildArtifactReferenceData $artifact, string $bytes): void
    {
        $this->discoverTaggedHandlers();

        throw_unless(strlen($bytes) === $artifact->sizeBytes, RuntimeException::class, sprintf(
            'Project build artifact [%s] size does not match its manifest reference.',
            $artifact->key,
        ));
        throw_unless(hash_equals($artifact->digest, hash('sha256', $bytes)), RuntimeException::class, sprintf(
            'Project build artifact [%s] digest does not match its manifest reference.',
            $artifact->key,
        ));

        $handler = $this->getItem($artifact->type);
        throw_unless($handler instanceof ProjectBuildArtifactHandler, RuntimeException::class, sprintf(
            'No project build artifact handler is registered for [%s].',
            $artifact->type,
        ));

        $handler->validate($artifact, $bytes);
    }

    /** @return list<string> */
    public function types(): array
    {
        $this->discoverTaggedHandlers();

        $types = array_keys($this->allItems());
        sort($types);

        return $types;
    }

    private function discoverTaggedHandlers(): void
    {
        if ($this->taggedHandlersDiscovered) {
            return;
        }

        $this->taggedHandlersDiscovered = true;
        foreach ($this->container->tagged(ProjectBuildArtifactHandler::TAG) as $handler) {
            throw_unless($handler instanceof ProjectBuildArtifactHandler, LogicException::class, 'Tagged project build artifact handlers must implement the project build artifact handler contract.');
            $this->register($handler);
        }
    }
}
