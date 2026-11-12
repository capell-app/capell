<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Privacy\Fixtures;

use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Override;
use RuntimeException;

/**
 * An eraser that fails partway, with the person's address in its message.
 */
final class ThrowingPrivacyEraser implements PrivacySubjectEraser
{
    #[Override]
    public function contributor(): string
    {
        return 'broken-store';
    }

    #[Override]
    public function supports(PrivacySubject $subject): bool
    {
        return true;
    }

    #[Override]
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData
    {
        throw new RuntimeException('Could not delete rows for ' . $subject->email);
    }

    #[Override]
    public function retainsSuppressionMarker(): bool
    {
        return false;
    }
}
