<?php

declare(strict_types=1);

namespace Capell\Admin\Enums\Extensions;

/**
 * Why an installed extension needs an operator to act before it works as
 * expected. Available updates, passive notices and an unconnected
 * Marketplace account are deliberately absent: each has its own indicator, and
 * counting them here made almost every extension "need attention".
 */
enum ExtensionAttentionReason: string
{
    case Blocked = 'blocked';
    case MissingTables = 'missing_tables';
    case CriticalAlert = 'critical_alert';
    case WarningAlert = 'warning_alert';
    case InfoAlert = 'info_alert';
    case ProviderQuarantined = 'provider_quarantined';
    case ComposerDrift = 'composer_drift';

    public function label(): string
    {
        return (string) match ($this) {
            self::Blocked => __('capell-admin::generic.extension_attention_reason_blocked'),
            self::MissingTables => __('capell-admin::generic.extension_attention_reason_missing_tables'),
            self::CriticalAlert => __('capell-admin::generic.extension_attention_reason_critical_alert'),
            self::WarningAlert => __('capell-admin::generic.extension_attention_reason_warning_alert'),
            self::InfoAlert => __('capell-admin::generic.extension_attention_reason_info_alert'),
            self::ProviderQuarantined => __('capell-admin::generic.extension_attention_reason_provider_quarantined'),
            self::ComposerDrift => __('capell-admin::generic.extension_attention_reason_composer_drift'),
        };
    }
}
