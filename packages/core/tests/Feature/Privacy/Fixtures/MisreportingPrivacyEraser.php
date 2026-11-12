<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Privacy\Fixtures;

use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Override;

/**
 * An eraser whose receipt claims another package's key, or a suppression marker
 * it never declared.
 */
final class MisreportingPrivacyEraser implements PrivacySubjectEraser
{
    public function __construct(private readonly string $receiptContributor = 'someone-else', private readonly bool $keepsMarker = false) {}

    #[Override]
    public function contributor(): string
    {
        return 'misreporting';
    }

    #[Override]
    public function supports(PrivacySubject $subject): bool
    {
        return true;
    }

    #[Override]
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData
    {
        return new PrivacyErasureReceiptData($this->receiptContributor, $mode, 0, suppressionMarkerRetained: $this->keepsMarker);
    }

    #[Override]
    public function retainsSuppressionMarker(): bool
    {
        return false;
    }
}
