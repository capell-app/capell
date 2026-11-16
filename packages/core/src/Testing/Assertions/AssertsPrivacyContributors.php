<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Capell\Core\Support\Security\PersonalDataSourceScanner;

/**
 * Asserts that a package storing personal data registers both a Core privacy exporter and an eraser, so an access
 * request returns that data and an erasure request removes it, and that no package registers only one side. A package
 * whose personal-data columns hold no one's data that a request could find opts out with a decision record.
 */
final class AssertsPrivacyContributors
{
    public const string CONTRACT_ID = 'privacy.personal-data';

    public static function run(string $packageRoot): void
    {
        $scanner = new PersonalDataSourceScanner;
        $columns = $scanner->personalDataColumns($packageRoot);
        $exporter = $scanner->registersExporter($packageRoot);
        $eraser = $scanner->registersEraser($packageRoot);

        if ($exporter && $eraser) {
            return;
        }

        if ($columns !== []) {
            self::fail($packageRoot, sprintf(
                "stores personal data (%s) but does not register both a privacy exporter and an eraser. Implement Core's PrivacySubjectExporter and PrivacySubjectEraser and tag them with PrivacySubjectRegistry::EXPORTER_TAG and ERASER_TAG.",
                implode(', ', $columns),
            ));
        }

        if ($exporter || $eraser) {
            self::fail($packageRoot, sprintf(
                'registers a privacy %s but no %s; a request would %s the data without %s it.',
                $exporter ? 'exporter' : 'eraser',
                $exporter ? 'eraser' : 'exporter',
                $exporter ? 'return' : 'remove',
                $exporter ? 'being able to remove' : 'first returning',
            ));
        }
    }

    private static function fail(string $packageRoot, string $message): never
    {
        throw new AssertionError(sprintf('[%s] %s: %s', self::CONTRACT_ID, $packageRoot, $message));
    }
}
