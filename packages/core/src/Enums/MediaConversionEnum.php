<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Spatie\Image\Enums\Fit;

enum MediaConversionEnum: string
{
    case Thumbnail = 'thumbnail';

    case Small = 'small';

    case Medium = 'medium';

    case Large = 'large';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        /** @var list<string>|null $values */
        static $values = null;

        if (is_array($values)) {
            return $values;
        }

        $values = array_values(array_map(
            static fn (self $conversion): string => $conversion->value,
            self::cases(),
        ));

        return $values;
    }

    /**
     * @return array<string,array{width:int,height:int}>
     */
    public static function defaultDimensionsByConversionValue(): array
    {
        static $dimensions = null;

        if (is_array($dimensions)) {
            return $dimensions;
        }

        $dimensions = [];

        foreach (self::cases() as $conversion) {
            $dimensions[$conversion->value] = $conversion->defaultDimensions();
        }

        return $dimensions;
    }

    public function defaultWidth(): int
    {
        return match ($this) {
            self::Thumbnail => 320,
            self::Small => 640,
            self::Medium => 1280,
            self::Large => 2560,
        };
    }

    public function defaultHeight(): int
    {
        return match ($this) {
            self::Thumbnail => 320,
            self::Small => 640,
            self::Medium => 1280,
            self::Large => 2560,
        };
    }

    public function fit(): Fit
    {
        return match ($this) {
            self::Thumbnail => Fit::Crop,
            default => Fit::Max,
        };
    }

    public function format(): string
    {
        return 'webp';
    }

    /**
     * @return array{width:int,height:int}
     */
    public function defaultDimensions(): array
    {
        return [
            'width' => $this->defaultWidth(),
            'height' => $this->defaultHeight(),
        ];
    }
}
