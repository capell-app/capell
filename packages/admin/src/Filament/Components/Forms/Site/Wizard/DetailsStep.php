<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site\Wizard;

use Capell\Admin\Actions\Sites\ReconcileWizardSiteDomainsAction;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Override;

class DetailsStep extends Step
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('capell-admin::tab.details'))
            ->afterValidation(function (Get $get, Set $set): void {
                $languages = array_merge([$get('language_id')], (array) $get('languages'));

                $domains = ReconcileWizardSiteDomainsAction::run(
                    (array) $get('site_domains'),
                    $languages,
                    request()->schemeAndHttpHost(),
                );

                $set('site_domains', $domains);
            });
    }
}
