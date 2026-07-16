<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Settings;

use Capell\Core\Contracts\SettingsContract;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Spatie\LaravelSettings\Settings;

class ThemeStudioSettings extends Settings implements SettingsContract, ThemeRuntimeSettings
{
    public string $activeTheme = 'corporate';

    public string $activePreset = 'boardroom';

    public ?string $draftTheme = null;

    public ?string $draftPreset = null;

    public ?int $draftWorkspaceId = null;

    /** @var array<string, string> */
    public array $brandProfile = [
        'primaryColor' => '#1a2d6d',
        'accentColor' => '#92400e',
        'neutralColor' => '#111827',
        'headingFont' => 'inter',
        'bodyFont' => 'inter',
        'spacing' => 'balanced',
        'alignment' => 'left',
        'cardStyle' => 'subtle',
        'navigationStyle' => 'standard',
        'layoutPresentation' => 'structured',
        'motionIntensity' => 'subtle',
        'mediaTreatment' => 'natural',
        'radius' => 'md',
        'surfaceColor' => '#ffffff',
        'foregroundColor' => '#111827',
        'headingScale' => 'balanced',
        'cardDensity' => 'comfortable',
        'overlayTreatment' => 'subtle',
    ];

    // @phpstan-ignore-next-line missingType.iterableValue (Theme override payloads are keyed by extension-defined token names.)
    public array $themeOverrides = [];

    public static function group(): string
    {
        return 'theme_studio';
    }

    public function activeTheme(): string
    {
        return $this->activeTheme;
    }

    public function activePreset(): string
    {
        return $this->activePreset;
    }

    public function brandProfile(): BrandProfileData
    {
        return BrandProfileData::from($this->brandProfile);
    }

    public function themeOverrides(): array
    {
        return $this->themeOverrides;
    }
}
