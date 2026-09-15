<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Admin\Actions\PersistMissingSettingsDefaultsAction;
use Capell\Marketplace\Settings\MarketplaceSettings;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UpdateMarketplaceSettingsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): MarketplaceSettings
    {
        PersistMissingSettingsDefaultsAction::run(MarketplaceSettings::class);

        $settings = resolve(MarketplaceSettings::class);
        $settings->fill([
            'owner_name' => $this->nullableString($data['owner_name'] ?? null),
            'owner_email' => $this->nullableString($data['owner_email'] ?? null),
            'owner_organisation' => $this->nullableString($data['owner_organisation'] ?? null),
            'security_notifications_enabled' => (bool) ($data['security_notifications_enabled'] ?? false),
            'bug_notifications_enabled' => (bool) ($data['bug_notifications_enabled'] ?? false),
            'marketing_notifications_enabled' => (bool) ($data['marketing_notifications_enabled'] ?? false),
        ]);
        $settings->save();

        return $settings;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
