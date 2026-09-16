<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Settings\MarketplaceSettings;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

final class BuildMarketplaceOwnerContactPayloadAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        try {
            $settings = resolve(MarketplaceSettings::class);
        } catch (MissingSettings) {
            return [];
        }

        $email = $this->nullableString($settings->owner_email);

        if ($email === null) {
            return [];
        }

        return [
            'email' => $email,
            'name' => $this->nullableString($settings->owner_name),
            'organisation' => $this->nullableString($settings->owner_organisation),
            'security_notifications_enabled' => $settings->security_notifications_enabled,
            'bug_notifications_enabled' => $settings->bug_notifications_enabled,
            'marketing_notifications_enabled' => $settings->marketing_notifications_enabled,
        ];
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
