<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Widgets;

use Capell\Admin\Actions\CheckSiteLanguagesMissingDomainsAction;
use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;
use Capell\Core\Models\Site;
use Illuminate\Support\Collection;

class SiteAlertsWidget extends ResourceAlertsFilamentWidget
{
    public ?Site $record = null;

    /**
     * @return Collection<string, MessageData>
     */
    protected function buildAlerts(): Collection
    {
        $alerts = collect();

        if (! $this->record instanceof Site) {
            return $alerts;
        }

        $missingLanguages = CheckSiteLanguagesMissingDomainsAction::run($this->record);

        if ($missingLanguages->isNotEmpty()) {
            $alerts->put(
                'missingLanguage',
                new MessageData(
                    message: __(
                        'capell-admin::message.site_languages_missing_domains',
                        [
                            'languages' => $missingLanguages->pluck('name')->join(', '),
                        ],
                    ),
                    type: AlertTypeEnum::Warning,
                    icon: 'heroicon-o-exclamation-triangle',
                ),
            );
        }

        return $alerts;
    }
}
