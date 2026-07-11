<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\SetActiveThemeAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
    Blueprint::factory()->theme()->default()->create();
});

it('sets exactly one active theme and enables the selected theme', function (): void {
    $activeTheme = Theme::factory()->createOne([
        'default' => true,
        'status' => true,
    ]);

    $selectedTheme = Theme::factory()->createOne([
        'default' => false,
        'status' => false,
    ]);

    $otherTheme = Theme::factory()->createOne([
        'default' => false,
        'status' => true,
    ]);

    SetActiveThemeAction::run($selectedTheme);

    expect($selectedTheme->refresh())
        ->default->toBeTrue()
        ->status->toBeTrue()
        ->and($activeTheme->refresh()->default)->toBeFalse()
        ->and($otherTheme->refresh()->default)->toBeFalse()
        ->and(Theme::query()->default()->count())->toBe(1);
});
