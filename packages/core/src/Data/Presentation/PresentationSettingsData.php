<?php

declare(strict_types=1);

namespace Capell\Core\Data\Presentation;

use Capell\Core\Enums\PresentationAlignment;
use Capell\Core\Enums\PresentationConnectionRequirement;
use Capell\Core\Enums\PresentationDeliveryMode;
use Capell\Core\Enums\PresentationDeviceVisibility;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Enums\PresentationWidthMode;
use Spatie\LaravelData\Data;

final class PresentationSettingsData extends Data
{
    private const int MAX_CUSTOM_WIDTH_LENGTH = 80;

    /**
     * @var array<int, string>
     */
    private const array SAFE_CUSTOM_WIDTH_TOKENS = [
        'calc',
        'clamp',
        'em',
        'max',
        'min',
        'px',
        'rem',
        'vh',
        'vw',
    ];

    public function __construct(
        public PresentationDeliveryMode $deliveryMode = PresentationDeliveryMode::ServerRendered,
        public PresentationDeviceVisibility $deviceVisibility = PresentationDeviceVisibility::All,
        public PresentationConnectionRequirement $connectionRequirement = PresentationConnectionRequirement::Any,
        public PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        public PresentationWidthMode $widthMode = PresentationWidthMode::Inherit,
        public PresentationAlignment $alignment = PresentationAlignment::Stretch,
        public ?string $presentationPreset = null,
        public ?int $minViewportWidth = null,
        public ?int $maxViewportWidth = null,
        public ?string $customWidth = null,
    ) {}

    public static function normalizeCustomWidth(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_CUSTOM_WIDTH_LENGTH) {
            return null;
        }

        if (preg_match('/[;"\'<>{}\r\n\\\\]/', $value) === 1) {
            return null;
        }

        if (preg_match('/\b(?:attr|expression|import|url|var)\s*\(/i', $value) === 1) {
            return null;
        }

        if (preg_match('/^\d+(\.\d+)?(px|rem|em|vw|vh|%)$/i', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(?:min|max|clamp|calc)\([0-9a-z\s.,+\-*\/()%]+\)$/i', $value) !== 1) {
            return null;
        }

        preg_match_all('/[a-z]+/i', $value, $tokens);

        foreach ($tokens[0] as $token) {
            if (! in_array(strtolower($token), self::SAFE_CUSTOM_WIDTH_TOKENS, true)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicRuntimePayload(): array
    {
        return array_filter([
            'delivery_mode' => $this->deliveryMode->value,
            'device_visibility' => $this->deviceVisibility->value,
            'connection_requirement' => $this->connectionRequirement->value,
            'loading_strategy' => $this->loadingStrategy->value,
            'width_mode' => $this->widthMode->value,
            'alignment' => $this->alignment->value,
            'min_viewport_width' => $this->minViewportWidth,
            'max_viewport_width' => $this->maxViewportWidth,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function usesCustomWidth(): bool
    {
        return $this->publicCustomWidth() !== null;
    }

    public function publicCustomWidth(): ?string
    {
        if ($this->widthMode !== PresentationWidthMode::Custom) {
            return null;
        }

        return self::normalizeCustomWidth($this->customWidth);
    }
}
