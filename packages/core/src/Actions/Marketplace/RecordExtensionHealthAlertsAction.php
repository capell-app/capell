<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Data\Marketplace\ExtensionHealthAlertData;
use Capell\Core\Models\ExtensionHealthAlert;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordExtensionHealthAlertsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, ExtensionHealthAlertData>  $alerts
     */
    public function handle(string $source, array $alerts): void
    {
        foreach ($alerts as $alert) {
            if ($alert->alertId === '') {
                continue;
            }

            ExtensionHealthAlert::query()->updateOrCreate(
                ['alert_id' => $alert->alertId],
                [
                    'source' => $source,
                    'extension_slug' => $alert->extensionSlug,
                    'composer_name' => $alert->composerName,
                    'affected_site_id' => $alert->siteId,
                    'affected_install_id' => $alert->installId,
                    'severity' => $alert->severity,
                    'category' => $alert->category,
                    'title' => $alert->title,
                    'message' => $alert->message,
                    'required_action' => $alert->requiredAction,
                    'runtime_disabled' => $alert->runtimeDisabled,
                    'protected_actions_blocked' => $alert->protectedActionsBlocked,
                    'issued_at' => $alert->issuedAt,
                    'expires_at' => $alert->expiresAt,
                    'signature' => $alert->signature ?? '',
                    'payload' => $alert->toArray(),
                ],
            );
        }
    }
}
