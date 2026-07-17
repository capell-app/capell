<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordThemeInstallIntentAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        string $extensionSlug,
        string $extensionName,
        string $composerName,
        string $composerCommand,
        ?string $versionConstraint,
        ?string $imageUrl,
        ?string $description,
        array $metadata = [],
    ): MarketplaceInstallIntent {
        $recordedAt = now();

        $attributes = (new MarketplaceInstallIntent)->forceFill([
            'composer_name' => $composerName,
            'extension_slug' => $extensionSlug,
            'extension_name' => $extensionName,
            'kind' => 'theme',
            'status' => MarketplaceInstallIntentStatus::Pending,
            'composer_command' => $composerCommand,
            'version_constraint' => $versionConstraint,
            'metadata' => array_filter([
                'image_url' => $imageUrl,
                'description' => $description,
                'acquisition' => $metadata !== [] ? $metadata : null,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
            'resolved_at' => null,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ])->getAttributes();

        MarketplaceInstallIntent::query()->upsert(
            $attributes,
            ['composer_name', 'kind'],
            [
                'extension_slug',
                'extension_name',
                'status',
                'composer_command',
                'version_constraint',
                'metadata',
                'resolved_at',
                'updated_at',
            ],
        );

        return MarketplaceInstallIntent::query()
            ->where('composer_name', $composerName)
            ->where('kind', 'theme')
            ->firstOrFail();
    }
}
