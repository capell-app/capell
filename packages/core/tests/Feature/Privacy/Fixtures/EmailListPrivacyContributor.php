<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Privacy\Fixtures;

use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Contracts\Privacy\PrivacySubjectExporter;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Override;

/**
 * A package that keeps guest data by email address and a suppression marker
 * after erasure, like a mailing list.
 */
final class EmailListPrivacyContributor implements PrivacySubjectEraser, PrivacySubjectExporter
{
    /** @var array<string, list<string>> */
    public static array $entries = [];

    #[Override]
    public function contributor(): string
    {
        return 'mailing-list';
    }

    #[Override]
    public function supports(PrivacySubject $subject): bool
    {
        return $subject->email !== null;
    }

    #[Override]
    public function export(PrivacySubject $subject): array
    {
        $lists = self::$entries[(string) $subject->email] ?? [];

        return $lists === [] ? [] : ['lists' => $lists];
    }

    #[Override]
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData
    {
        $count = count(self::$entries[(string) $subject->email] ?? []);
        unset(self::$entries[(string) $subject->email]);

        return new PrivacyErasureReceiptData($this->contributor(), $mode, $count, suppressionMarkerRetained: true);
    }

    #[Override]
    public function retainsSuppressionMarker(): bool
    {
        return true;
    }
}
