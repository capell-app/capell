<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Pages;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\FixFormDataWithMediaInsideState;
use Capell\Admin\Filament\Concerns\Validate\SiteDomainValidation;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Filament\RawState;
use Capell\Core\Actions\SiteCreatedAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Livewire\Attributes\Url;
use Override;

/**
 * @property-read Site $record
 */
class CreateSite extends CreateRecord
{
    use FixFormDataWithMediaInsideState;
    use SiteDomainValidation;

    #[Url]
    public ?string $type = null;

    /** @var array<string, mixed> */
    private array $siteDomains = [];

    /** @return class-string<SiteResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<SiteResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Site);

        return $resource;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        $resource = static::getResource();

        return $resource::configuredForm($schema, ConfiguratorContextData::forCreate(
            ConfiguratorTypeEnum::Site,
            $this->type,
        ));
    }

    #[Override]
    protected function getFormActions(): array
    {
        return [];
    }

    protected function afterCreate(): void
    {
        $rawState = RawState::array($this->form->getRawState());
        $data = is_array($this->data) ? $this->data : [];

        SiteCreatedAction::run($this->record, [
            ...$data,
            'site_domains' => is_array($rawState['site_domains'] ?? null) ? $rawState['site_domains'] : $this->siteDomains,
        ]);

        $this->createSiteDomainsFromState(is_array($rawState['site_domains'] ?? null) ? $rawState['site_domains'] : $this->siteDomains);
    }

    /**
     * @throws Halt
     */
    protected function beforeCreate(): void
    {
        if (isset($this->data['site_domains']) && $this->data['site_domains'] !== []) {
            $this->siteDomains = $this->data['site_domains'];
        }

        foreach ($this->siteDomains as &$siteDomain) {
            $urlParts = parse_url((string) $siteDomain['url']);

            if ($urlParts === false) {
                $this->halt();
            }

            $urlParts = [
                'scheme' => $urlParts['scheme'] ?? null,
                'host' => $urlParts['host'] ?? null,
                'path' => $urlParts['path'] ?? null,
            ];

            if (($siteDomain['use_host_domain'] ?? false) === true) {
                $urlParts['host'] = null;
            }

            if (! static::validateExists($urlParts)) {
                $this->halt();
            }
        }
    }

    protected function beforeValidate(): void
    {
        $rawState = RawState::array($this->form->getRawState());

        $this->siteDomains = isset($rawState['site_domains']) && is_array($rawState['site_domains'])
            ? $rawState['site_domains']
            : [];
    }

    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $formData = is_array($this->data) ? $this->data : [];

        $data['blueprint_id'] ??= $formData['blueprint_id'] ?? null;
        $data['theme_id'] ??= $formData['theme_id'] ?? null;
        $data['meta']['mail']['use_site_logo'] ??= true;

        return $this->fixFormDataWithMediaInsideState($data);
    }

    /**
     * @param  array<string, mixed>  $domains
     */
    private function createSiteDomainsFromState(array $domains): void
    {
        if ($this->record->siteDomains()->exists()) {
            return;
        }

        foreach ($domains as $domain) {
            if (! is_array($domain)) {
                continue;
            }

            if (! isset($domain['url'])) {
                continue;
            }

            $urlParts = parse_url((string) $domain['url']);

            if ($urlParts === false) {
                continue;
            }

            SiteDomain::query()->create([
                'site_id' => $this->record->getKey(),
                'language_id' => $domain['language_id'] ?? $this->record->language_id,
                'scheme' => $urlParts['scheme'] ?? null,
                'domain' => ($domain['use_host_domain'] ?? false) === true ? null : ($urlParts['host'] ?? null),
                'path' => isset($urlParts['path']) && ! in_array(mb_rtrim($urlParts['path'], '/'), ['', '0'], true)
                    ? mb_rtrim($urlParts['path'], '/')
                    : null,
                'default' => (bool) ($domain['default'] ?? false),
                'status' => (bool) ($domain['status'] ?? true),
            ]);
        }
    }
}
