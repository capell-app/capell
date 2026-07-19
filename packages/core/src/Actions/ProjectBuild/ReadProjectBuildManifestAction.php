<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestConstraints;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestMigrationRegistry;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReadProjectBuildManifestAction
{
    use AsFake;
    use AsObject;

    public const int CurrentSchemaVersion = ProjectBuildManifestConstraints::CURRENT_SCHEMA_VERSION;

    public function __construct(
        private readonly ProjectBuildManifestMigrationRegistry $migrations,
    ) {}

    public function handle(string $json): ProjectBuildManifestData
    {
        try {
            $payload = JsonCodec::decode($json);
        } catch (JsonException) {
            throw ValidationException::withMessages(['manifest' => 'The project build manifest must contain valid JSON.']);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages(['manifest' => 'The project build manifest must contain a JSON object.']);
        }

        return ValidateProjectBuildManifestAction::run(
            $this->migrations->migrate($payload, self::CurrentSchemaVersion),
        );
    }
}
