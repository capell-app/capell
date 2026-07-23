<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricScopeType;
use DateTimeZone;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricScopeData extends Data
{
    public function __construct(
        public readonly MetricScopeType $type,
        public readonly string $timezone,
        public readonly string $dayStartsAt,
        public readonly ?string $siteUuid = null,
        public readonly ?string $language = null,
    ) {
        $hasSite = $this->siteUuid !== null;
        $hasLanguage = $this->language !== null;

        throw_if(($this->type === MetricScopeType::Global && ($hasSite || $hasLanguage))
            || ($this->type === MetricScopeType::Site && (! $hasSite || $hasLanguage))
            || ($this->type === MetricScopeType::SiteLanguage && (! $hasSite || ! $hasLanguage)), InvalidArgumentException::class, 'Metric scope identifiers do not match its type.');

        throw_if($hasSite && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $this->siteUuid) !== 1, InvalidArgumentException::class, 'Metric site UUID must be a canonical lowercase UUID.');

        throw_if($hasLanguage && $this->language !== $this->canonicalLanguage($this->language), InvalidArgumentException::class, 'Metric language must be a canonical language tag.');

        throw_unless(in_array($this->timezone, DateTimeZone::listIdentifiers(), true), InvalidArgumentException::class, 'Metric timezone must be an IANA timezone identifier.');

        throw_if(preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d\z/', $this->dayStartsAt) !== 1, InvalidArgumentException::class, 'Metric day boundary must use HH:MM:SS.');
    }

    public static function global(string $timezone, string $dayStartsAt = '00:00:00'): self
    {
        return new self(MetricScopeType::Global, $timezone, $dayStartsAt);
    }

    public static function site(string $siteUuid, string $timezone, string $dayStartsAt = '00:00:00'): self
    {
        return new self(MetricScopeType::Site, $timezone, $dayStartsAt, $siteUuid);
    }

    public static function siteLanguage(
        string $siteUuid,
        string $language,
        string $timezone,
        string $dayStartsAt = '00:00:00',
    ): self {
        return new self(MetricScopeType::SiteLanguage, $timezone, $dayStartsAt, $siteUuid, $language);
    }

    public function key(): string
    {
        return implode(':', array_filter([
            $this->type->value,
            $this->siteUuid,
            $this->language,
            $this->timezone,
            $this->dayStartsAt,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @return array{type: string, timezone: string, day_starts_at: string, site_uuid: string|null, language: string|null}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'timezone' => $this->timezone,
            'day_starts_at' => $this->dayStartsAt,
            'site_uuid' => $this->siteUuid,
            'language' => $this->language,
        ];
    }

    private function canonicalLanguage(string $language): string
    {
        if (preg_match('/\A[a-zA-Z]{2,3}(?:-[a-zA-Z]{4})?(?:-(?:[a-zA-Z]{2}|\d{3}))?\z/', $language) !== 1) {
            return '';
        }

        $parts = explode('-', $language);
        $canonical = [strtolower(array_shift($parts))];

        foreach ($parts as $part) {
            $canonical[] = strlen($part) === 4
                ? ucfirst(strtolower($part))
                : (strlen($part) === 2 ? strtoupper($part) : $part);
        }

        return implode('-', $canonical);
    }
}
