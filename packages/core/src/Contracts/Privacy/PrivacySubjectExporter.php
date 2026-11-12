<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Data\Privacy\PrivacySubject;

/**
 * Exports one package's personal data about a person for an access request.
 *
 * Register an implementation with the container tag
 * PrivacySubjectRegistry::EXPORTER_TAG (or
 * PrivacySubjectRegistry::registerExporter()). A privacy request handler, such as
 * the privacy-center package, collects every exporter from the Core registry, so a
 * package never needs to know which handler is installed.
 */
interface PrivacySubjectExporter
{
    /**
     * A stable key for the package's section of the export, such as `comments`.
     * Lower case letters, digits, dots, dashes and underscores.
     */
    public function contributor(): string;

    /**
     * Whether this package can hold data about the subject (for example, only
     * user accounts, or any subject with an email address).
     */
    public function supports(PrivacySubject $subject): bool;

    /**
     * The package's data about the subject, as a JSON-serialisable array. An
     * empty array means the package holds nothing about them.
     *
     * @return array<string, mixed>
     */
    public function export(PrivacySubject $subject): array;
}
