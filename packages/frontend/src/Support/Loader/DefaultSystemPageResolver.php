<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Contracts\SystemPageResolver;
use Capell\Frontend\Settings\FrontendSettings;
use Throwable;

final class DefaultSystemPageResolver implements SystemPageResolver
{
    public function resolve(string $type, Site $site, Language $language): ?Pageable
    {
        if (! $this->customSystemPageEnabled($type)) {
            return null;
        }

        $page = PageLoader::getSystemPage($site, $language, $type);

        if ($page instanceof Pageable || ! $this->shouldAutoCreateMissingPage($type)) {
            return $page;
        }

        return match ($type) {
            PageTypeEnum::NotFound->value => resolve(PageCreator::class)->createErrorPage($site, $site->getAllLanguages()),
            PageTypeEnum::Maintenance->value => resolve(PageCreator::class)->createMaintenancePage($site, $site->getAllLanguages()),
            default => null,
        };
    }

    private function customSystemPageEnabled(string $type): bool
    {
        try {
            $settings = resolve(FrontendSettings::class);

            return match ($type) {
                PageTypeEnum::NotFound->value => $settings->custom_error_page_enabled,
                PageTypeEnum::Maintenance->value => $settings->custom_maintenance_page_enabled,
                default => true,
            };
        } catch (Throwable) {
            return true;
        }
    }

    private function shouldAutoCreateMissingPage(string $type): bool
    {
        if (! in_array($type, [PageTypeEnum::NotFound->value, PageTypeEnum::Maintenance->value], true)) {
            return false;
        }

        return config('capell-frontend.system_pages.auto_create_missing', true) === true;
    }
}
