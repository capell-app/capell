<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\RecordThemeInstallIntentAction;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Carbon\CarbonImmutable;

it('records a pending theme install intent', function (): void {
    $intent = RecordThemeInstallIntentAction::run(
        extensionSlug: 'agency-theme',
        extensionName: 'Agency Theme',
        composerName: 'capell-app/theme-agency',
        composerCommand: 'composer require capell-app/theme-agency:^1.2',
        versionConstraint: '^1.2',
        imageUrl: 'https://marketplace.test/theme.png',
        description: 'A polished agency theme.',
        metadata: [
            'signed_activation' => ['activation_id' => 'act_123'],
        ],
    );

    expect($intent)->toBeInstanceOf(MarketplaceInstallIntent::class)
        ->and($intent->composer_name)->toBe('capell-app/theme-agency')
        ->and($intent->extension_slug)->toBe('agency-theme')
        ->and($intent->extension_name)->toBe('Agency Theme')
        ->and($intent->kind)->toBe('theme')
        ->and($intent->status)->toBe(MarketplaceInstallIntentStatus::Pending)
        ->and($intent->composer_command)->toBe('composer require capell-app/theme-agency:^1.2')
        ->and($intent->version_constraint)->toBe('^1.2')
        ->and($intent->resolved_at)->toBeNull()
        ->and($intent->metadata)->toMatchArray([
            'image_url' => 'https://marketplace.test/theme.png',
            'description' => 'A polished agency theme.',
            'acquisition' => [
                'signed_activation' => ['activation_id' => 'act_123'],
            ],
        ]);
});

it('refreshes an existing pending intent instead of duplicating it', function (): void {
    $initialRecordedAt = CarbonImmutable::parse('2026-05-07 10:00:00');
    $refreshedRecordedAt = CarbonImmutable::parse('2026-05-07 10:01:00');

    CarbonImmutable::setTestNow($initialRecordedAt);

    try {
        $firstIntent = RecordThemeInstallIntentAction::run(
            extensionSlug: 'agency-theme',
            extensionName: 'Agency Theme',
            composerName: 'capell-app/theme-agency',
            composerCommand: 'composer require capell-app/theme-agency:^1.0',
            versionConstraint: '^1.0',
            imageUrl: null,
            description: null,
        );

        CarbonImmutable::setTestNow($refreshedRecordedAt);

        $intent = RecordThemeInstallIntentAction::run(
            extensionSlug: 'agency-theme',
            extensionName: 'Agency Theme Pro',
            composerName: 'capell-app/theme-agency',
            composerCommand: 'composer require capell-app/theme-agency:^1.2',
            versionConstraint: '^1.2',
            imageUrl: 'https://marketplace.test/theme.png',
            description: 'Updated description.',
        );

        expect(MarketplaceInstallIntent::query()->count())->toBe(1)
            ->and($intent->id)->toBe($firstIntent->id)
            ->and($intent->extension_name)->toBe('Agency Theme Pro')
            ->and($intent->composer_command)->toBe('composer require capell-app/theme-agency:^1.2')
            ->and($intent->version_constraint)->toBe('^1.2')
            ->and($intent->created_at)->toEqual($initialRecordedAt)
            ->and($intent->updated_at)->toEqual($refreshedRecordedAt)
            ->and($intent->metadata)->toMatchArray([
                'image_url' => 'https://marketplace.test/theme.png',
                'description' => 'Updated description.',
            ]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});
