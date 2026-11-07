<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Security;

use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;

/**
 * Translated labels for the enum values the Security page shows.
 *
 * Every match arm carries a complete literal translation key so the
 * language-key audit can prove each key is referenced; a key built by
 * concatenation hides its usage from that audit.
 */
final class SecurityLabels
{
    public static function severity(SecurityWeaknessSeverity $severity): string
    {
        return match ($severity) {
            SecurityWeaknessSeverity::Low => (string) __('capell-admin::security.severities.low'),
            SecurityWeaknessSeverity::Medium => (string) __('capell-admin::security.severities.medium'),
            SecurityWeaknessSeverity::High => (string) __('capell-admin::security.severities.high'),
            SecurityWeaknessSeverity::Critical => (string) __('capell-admin::security.severities.critical'),
        };
    }

    public static function source(SecurityFindingSource $source): string
    {
        return match ($source) {
            SecurityFindingSource::Builtin => (string) __('capell-admin::security.sources.builtin'),
            SecurityFindingSource::Advisory => (string) __('capell-admin::security.sources.advisory'),
            SecurityFindingSource::Analyser => (string) __('capell-admin::security.sources.analyser'),
        };
    }

    public static function status(SecurityFindingStatus $status): string
    {
        return match ($status) {
            SecurityFindingStatus::Open => (string) __('capell-admin::security.statuses.open'),
            SecurityFindingStatus::Remediated => (string) __('capell-admin::security.statuses.remediated'),
            SecurityFindingStatus::Resolved => (string) __('capell-admin::security.statuses.resolved'),
            SecurityFindingStatus::Dismissed => (string) __('capell-admin::security.statuses.dismissed'),
        };
    }

    public static function outcome(SecurityRemediationOutcome $outcome): string
    {
        return match ($outcome) {
            SecurityRemediationOutcome::Applied => (string) __('capell-admin::security.outcomes.applied'),
            SecurityRemediationOutcome::AppliedPendingNodes => (string) __('capell-admin::security.outcomes.applied_pending_nodes'),
            SecurityRemediationOutcome::Failed => (string) __('capell-admin::security.outcomes.failed'),
            SecurityRemediationOutcome::Skipped => (string) __('capell-admin::security.outcomes.skipped'),
        };
    }

    /** @return array<string, string> */
    public static function severityOptions(): array
    {
        $options = [];
        foreach (SecurityWeaknessSeverity::cases() as $severity) {
            $options[$severity->value] = self::severity($severity);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        $options = [];
        foreach (SecurityFindingSource::cases() as $source) {
            $options[$source->value] = self::source($source);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $options = [];
        foreach (SecurityFindingStatus::cases() as $status) {
            $options[$status->value] = self::status($status);
        }

        return $options;
    }
}
