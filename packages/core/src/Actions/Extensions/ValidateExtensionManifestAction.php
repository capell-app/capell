<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Support\Manifest\ManifestValidator;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(array<string, mixed> $manifest, ?array<string, mixed> $composerJson = null, ?string $packageName = null, ?string $discoverySource = null)
 */
final class ValidateExtensionManifestAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>|null  $composerJson
     */
    public function handle(
        array $manifest,
        ?array $composerJson = null,
        ?string $packageName = null,
        ?string $discoverySource = null,
    ): void {
        resolve(ManifestValidator::class)->validate(
            data: $manifest,
            composerJson: $composerJson,
            packageName: $packageName,
            discoverySource: $discoverySource,
        );
    }
}
