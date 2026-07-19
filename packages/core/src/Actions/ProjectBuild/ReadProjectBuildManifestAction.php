<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestMigrationRegistry;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReadProjectBuildManifestAction
{
    use AsObject;

    public const int CurrentSchemaVersion = 1;

    public function __construct(
        private readonly ProjectBuildManifestMigrationRegistry $migrations,
    ) {}

    public function handle(string $json): ProjectBuildManifestData
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
