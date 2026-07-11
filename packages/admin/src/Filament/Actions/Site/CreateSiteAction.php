<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Site;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Actions\SiteCreatedAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Override;

class CreateSiteAction extends CreateAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->modalCancelAction(false)
            ->modalSubmitAction(false)
            ->createAnother(false)
            ->model(Site::class)
            ->resource(SiteResource::class)
            ->schema(function (Schema $schema, self $action): Schema {
                $arguments = $action->getArguments();
                $type = $arguments['type'] ?? null;

                return SiteResource::configuredForm($schema->operation('createOption'), ConfiguratorContextData::forCreate(
                    ConfiguratorTypeEnum::Site,
                    is_string($type) ? $type : null,
                ));
            })
            ->after(function (Site $record, self $action): void {
                $formData = $action->getRawData();

                SiteCreatedAction::run($record, $formData);
            });
    }

    #[Override]
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormData(array $data): array
    {
        $languages = [$data['language_id'] ?? null];

        if (isset($data['languages']) && is_array($data['languages']) && $data['languages'] !== []) {
            $languages = array_merge($languages, $data['languages']);
        }

        $data['site_domains'] = $this->defaultSiteDomains($languages);

        return $data;
    }

    /**
     * @param  array<int, mixed>  $languages
     * @return array<int, array{url: string, language_id: int|string|null, default: bool, use_host_domain: bool}>
     */
    private function defaultSiteDomains(array $languages): array
    {
        if ($languages === []) {
            return [];
        }

        return Language::query()->whereKey($languages)
            ->orderByDesc('default')
            ->get()
            ->map(fn (Language $language, int $index): array => [
                'url' => request()->schemeAndHttpHost() . ($language->default ? '' : '/' . $language->code),
                'language_id' => $language->id,
                'default' => $index === 0,
                'use_host_domain' => true,
            ])
            ->all();
    }
}
