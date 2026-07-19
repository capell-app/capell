<?php

declare(strict_types=1);

namespace Capell\Core\Support\ProjectBuild;

use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Illuminate\Contracts\Container\Container;
use LogicException;
use RuntimeException;

final class ProjectBuildArtifactHandlerRegistry
{
    /** @var array<string, ProjectBuildArtifactHandler> */
    private array $handlers = [];

    private bool $taggedHandlersDiscovered = false;

    public function __construct(private readonly Container $container) {}

    public function register(ProjectBuildArtifactHandler $handler): void
    {
        $type = $handler->type();

        throw_if($type === '', LogicException::class, 'Project build artifact handler types must not be empty.');
        throw_if(isset($this->handlers[$type]), LogicException::class, sprintf('A project build artifact handler is already registered for [%s].', $type));

        $this->handlers[$type] = $handler;
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

        $handler = $this->handlers[$artifact->type] ?? null;
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

        $types = array_keys($this->handlers);
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
            if ($handler instanceof ProjectBuildArtifactHandler) {
                $this->register($handler);
            }
        }
    }
}
