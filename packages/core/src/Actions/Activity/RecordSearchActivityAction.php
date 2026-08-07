<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Activity;

use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\Site;

final class RecordSearchActivityAction
{
    public function __construct(
        private readonly ActivitySettingsReader $settings,
        private readonly RecordActivityBucketAction $record,
    ) {}

    public static function normalize(string $term): ?string
    {
        $term = preg_replace('/\s+/u', ' ', mb_trim(mb_strtolower($term))) ?? '';

        if ($term === '' || mb_strlen($term) > 160) {
            return null;
        }

        $sensitivePatterns = [
            '/\A[^\s@]+@[^\s@]+\.[^\s@]+\z/u',
            '/\Ahttps?:\/\//iu',
            '/\A(?:\+?\d[\d\s().-]{6,}\d)\z/u',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $term) === 1) {
                return null;
            }
        }

        return $term;
    }

    public function execute(Site $site, string $language, string $term): bool
    {
        if (! $this->settings->searchCollectionEnabled()) {
            return false;
        }

        $term = self::normalize($term);

        if ($term === null) {
            return false;
        }

        $this->record->execute($site, $language, ActivityBucketSubjectEnum::SearchTerm, $term);

        return true;
    }
}
