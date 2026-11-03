<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use RuntimeException;

final class ExtensionVerificationFailedException extends RuntimeException
{
    public function __construct(public readonly ExtensionVerificationReportData $report)
    {
        $first = $report->failures[0] ?? null;
        $message = match (true) {
            $first === null => '',
            $first->code === ExtensionVerificationIssueData::MANIFEST_INVALID => (string) __('capell-core::extensions.verification.manifest_invalid_safe', ['package' => $report->composerName]),
            default => $first->message,
        };
        parent::__construct($report->composerName . ': ' . ($first !== null ? $message . ' ' . $first->nextAction : 'Extension verification failed.'));
    }
}
