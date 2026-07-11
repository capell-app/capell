<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use BackedEnum;
use Spatie\LaravelData\Data;

class BrandProfileData extends Data
{
    public function __construct(
        public string $primaryColor = '#1a2d6d',
        public string $accentColor = '#92400e',
        public string $neutralColor = '#111827',
        public string $headingFont = 'inter',
        public string $bodyFont = 'inter',
        public string $spacing = 'balanced',
        public string $alignment = 'left',
        public string $cardStyle = 'subtle',
        public string $navigationStyle = 'standard',
        public string $layoutPresentation = 'structured',
        public string $motionIntensity = 'subtle',
        public string $mediaTreatment = 'natural',
        public string $radius = 'md',
        public string $surfaceColor = '#ffffff',
        public string $foregroundColor = '#111827',
        public string $headingScale = 'balanced',
        public string $cardDensity = 'comfortable',
        public string $overlayTreatment = 'subtle',
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function merge(array $values): self
    {
        return new self(
            primaryColor: $this->stringValue($values['primaryColor'] ?? null, $this->primaryColor),
            accentColor: $this->stringValue($values['accentColor'] ?? null, $this->accentColor),
            neutralColor: $this->stringValue($values['neutralColor'] ?? null, $this->neutralColor),
            headingFont: $this->stringValue($values['headingFont'] ?? null, $this->headingFont),
            bodyFont: $this->stringValue($values['bodyFont'] ?? null, $this->bodyFont),
            spacing: $this->stringValue($values['spacing'] ?? null, $this->spacing),
            alignment: $this->stringValue($values['alignment'] ?? null, $this->alignment),
            cardStyle: $this->stringValue($values['cardStyle'] ?? null, $this->cardStyle),
            navigationStyle: $this->stringValue($values['navigationStyle'] ?? null, $this->navigationStyle),
            layoutPresentation: $this->stringValue($values['layoutPresentation'] ?? null, $this->layoutPresentation),
            motionIntensity: $this->stringValue($values['motionIntensity'] ?? null, $this->motionIntensity),
            mediaTreatment: $this->stringValue($values['mediaTreatment'] ?? null, $this->mediaTreatment),
            radius: $this->stringValue($values['radius'] ?? null, $this->radius),
            surfaceColor: $this->stringValue($values['surfaceColor'] ?? null, $this->surfaceColor),
            foregroundColor: $this->stringValue($values['foregroundColor'] ?? null, $this->foregroundColor),
            headingScale: $this->stringValue($values['headingScale'] ?? null, $this->headingScale),
            cardDensity: $this->stringValue($values['cardDensity'] ?? null, $this->cardDensity),
            overlayTreatment: $this->stringValue($values['overlayTreatment'] ?? null, $this->overlayTreatment),
        );
    }

    /**
     * @return array<string, string>
     */
    public function tokens(): array
    {
        return [
            '--theme-primary' => $this->primaryColor,
            '--theme-accent' => $this->accentColor,
            '--theme-neutral' => $this->neutralColor,
            '--theme-heading-font' => $this->fontStack($this->headingFont),
            '--theme-body-font' => $this->fontStack($this->bodyFont),
            '--theme-spacing' => $this->spacing,
            '--theme-alignment' => $this->alignment,
            '--theme-card-style' => $this->cardStyle,
            '--theme-navigation-style' => $this->navigationStyle,
            '--theme-layout-presentation' => $this->layoutPresentation,
            '--theme-motion-intensity' => $this->motionIntensity,
            '--theme-media-treatment' => $this->mediaTreatment,
            '--theme-radius' => $this->allowed($this->radius, ['none', 'sm', 'md', 'lg', 'xl'], 'md'),
            '--theme-radius-value' => $this->radiusValue($this->radius),
            '--theme-surface' => $this->surfaceColor,
            '--theme-foreground' => $this->foregroundColor,
            '--theme-heading-scale' => $this->allowed($this->headingScale, ['compact', 'balanced', 'expressive'], 'balanced'),
            '--theme-heading-scale-ratio' => $this->headingScaleRatio($this->headingScale),
            '--theme-card-density' => $this->allowed($this->cardDensity, ['compact', 'comfortable', 'spacious'], 'comfortable'),
            '--theme-card-density-gap' => $this->cardDensityGap($this->cardDensity),
            '--theme-overlay-treatment' => $this->allowed($this->overlayTreatment, ['none', 'subtle', 'strong'], 'subtle'),
            '--theme-overlay-opacity' => $this->overlayOpacity($this->overlayTreatment),
        ];
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $fallback;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowed(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function fontStack(string $font): string
    {
        return match ($font) {
            'playfair' => "'Playfair Display', Georgia, serif",
            'sora' => "'Sora', 'Inter', system-ui, sans-serif",
            'manrope' => "'Manrope', 'Inter', system-ui, sans-serif",
            default => "'Inter', system-ui, sans-serif",
        };
    }

    private function radiusValue(string $radius): string
    {
        return match ($this->allowed($radius, ['none', 'sm', 'md', 'lg', 'xl'], 'md')) {
            'none' => '0',
            'sm' => '0.25rem',
            'lg' => '0.75rem',
            'xl' => '1rem',
            default => '0.5rem',
        };
    }

    private function headingScaleRatio(string $headingScale): string
    {
        return match ($this->allowed($headingScale, ['compact', 'balanced', 'expressive'], 'balanced')) {
            'compact' => '1.125',
            'expressive' => '1.25',
            default => '1.2',
        };
    }

    private function cardDensityGap(string $cardDensity): string
    {
        return match ($this->allowed($cardDensity, ['compact', 'comfortable', 'spacious'], 'comfortable')) {
            'compact' => '0.75rem',
            'spacious' => '1.5rem',
            default => '1rem',
        };
    }

    private function overlayOpacity(string $overlayTreatment): string
    {
        return match ($this->allowed($overlayTreatment, ['none', 'subtle', 'strong'], 'subtle')) {
            'none' => '0',
            'strong' => '0.65',
            default => '0.35',
        };
    }
}
