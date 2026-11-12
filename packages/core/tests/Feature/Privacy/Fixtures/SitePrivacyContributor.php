<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Privacy\Fixtures;

use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Contracts\Privacy\PrivacySubjectExporter;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacyExportSectionData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Capell\Core\Models\Site;
use Override;

/**
 * A package that keeps data only about one kind of record and stores a file
 * for it.
 */
final class SitePrivacyContributor implements PrivacySubjectEraser, PrivacySubjectExporter
{
    #[Override]
    public function contributor(): string
    {
        return 'site-notes';
    }

    #[Override]
    public function supports(PrivacySubject $subject): bool
    {
        return $subject->isA(Site::class);
    }

    #[Override]
    public function export(PrivacySubject $subject): PrivacyExportSectionData
    {
        return new PrivacyExportSectionData(['site' => $subject->reference()], ['local:site notes/' . $subject->model?->getKey() . '/My CV.pdf']);
    }

    #[Override]
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData
    {
        // Sites are kept for the business, so a delete request anonymises them.
        return new PrivacyErasureReceiptData($this->contributor(), PrivacyErasureMode::Anonymize, 1, ['local:site notes/' . $subject->model?->getKey() . '/My CV.pdf']);
    }

    #[Override]
    public function retainsSuppressionMarker(): bool
    {
        return false;
    }
}
