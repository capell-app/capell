<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionHealthAlertData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $packageName,
        public readonly string $severity,
        public readonly string $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $requiredAction = null,
        public readonly bool $runtimeDisabled = false,
        public readonly bool $protectedActionsBlocked = false,
        public readonly ?string $managementUrl = null,
        public readonly ?string $managementLabel = null,
        public readonly ?string $developerDetail = null,
    ) {}

    public function isActionable(): bool
    {
        return $this->runtimeDisabled || $this->protectedActionsBlocked
            || (is_string($this->requiredAction) && trim($this->requiredAction) !== '' && strtolower(trim($this->requiredAction)) !== 'none');
    }

    public function recoveryAction(): ?string
    {
        if (! $this->isActionable()) {
            return null;
        }

        if (is_string($this->requiredAction) && trim($this->requiredAction) !== '' && strtolower(trim($this->requiredAction)) !== 'none') {
            $action = trim($this->requiredAction);

            return match ($action) {
                'verify_site_or_buy_licence' => (string) __('capell-admin::generic.extension_alert_action_verify_site_or_buy_licence'),
                'update_extension', 'upgrade' => (string) __('capell-admin::generic.extension_alert_action_update_extension'),
                'review' => (string) __('capell-admin::generic.extension_alert_action_review'),
                default => preg_match('/^[a-z][a-z0-9_]*$/', $action) === 1
                    ? (string) __('capell-admin::generic.extension_alert_action_unknown')
                    : $action,
            };
        }

        $steps = [];
        if ($this->runtimeDisabled) {
            $steps[] = (string) __('capell-admin::generic.extension_alert_runtime_disabled_action');
        }

        if ($this->protectedActionsBlocked) {
            $steps[] = (string) __('capell-admin::generic.extension_alert_protected_actions_action');
        }

        return implode(' ', $steps);
    }

    /** @return array<string, mixed> */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'packageName' => $this->packageName,
            'severity' => $this->severity,
            'category' => $this->category,
            'title' => $this->title,
            'message' => $this->message,
            'requiredAction' => $this->recoveryAction(),
            'runtimeDisabled' => $this->runtimeDisabled,
            'protectedActionsBlocked' => $this->protectedActionsBlocked,
            'managementUrl' => $this->managementUrl,
            'managementLabel' => $this->managementLabel,
            'developerDetail' => $this->developerDetail,
        ];
    }
}
