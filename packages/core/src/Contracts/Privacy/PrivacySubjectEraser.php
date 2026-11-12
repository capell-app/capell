<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;

/**
 * Erases one package's personal data about a person for an erasure request.
 *
 * Register an implementation with the container tag
 * PrivacySubjectRegistry::ERASER_TAG (or
 * PrivacySubjectRegistry::registerEraser()). Erasure can run from the admin, a
 * console command or a queued job, so an eraser must not depend on a request or
 * a signed-in user, and must be safe to run again for the same subject.
 */
interface PrivacySubjectEraser
{
    /**
     * A stable key for the package, such as `comments`. Lower case letters,
     * digits, dots, dashes and underscores.
     */
    public function contributor(): string;

    public function supports(PrivacySubject $subject): bool;

    /**
     * Deletes or anonymises the package's data about the subject, including the
     * files it stored for them, and says what it did.
     */
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData;

    /**
     * Whether erasure keeps a one-way marker (a keyed hash, never the identifier
     * itself) so the person is not contacted or re-added afterwards, such as a
     * mailing-list suppression entry. Declared up front so a privacy request can
     * tell the person before it runs.
     */
    public function retainsSuppressionMarker(): bool;
}
