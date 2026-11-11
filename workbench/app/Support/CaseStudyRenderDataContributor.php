<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Models\Page;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use LogicException;
use Override;
use Workbench\App\Support\Data\CaseStudyPublicData;

final class CaseStudyRenderDataContributor implements PublicRenderDataContributor
{
    #[Override]
    public function key(): string
    {
        return 'case-study';
    }

    #[Override]
    public function supports(FrontendRenderContextData $context): bool
    {
        return ! $context->isError
            && $context->page instanceof Page
            && $context->page::class === Page::class
            && $context->page->blueprint?->key === 'case-study';
    }

    #[Override]
    public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
    {
        $page = $context->page;
        throw_unless($page instanceof Page, LogicException::class, 'Case study data requires a page.');

        return new PublicRenderDataContributionMetadataData(
            fingerprint: hash('sha256', json_encode($this->publicData($page), JSON_THROW_ON_ERROR)),
            cacheDependencies: [PublicRenderDataCacheDependencyData::forModel($page)],
        );
    }

    #[Override]
    public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
    {
        $page = $context->page;
        throw_unless($page instanceof Page, LogicException::class, 'Case study data requires a page.');

        return new PublicRenderDataContributionData($this->publicData($page));
    }

    #[Override]
    public function cacheDependencyModelTypes(): array
    {
        return [Page::class];
    }

    private function publicData(Page $page): CaseStudyPublicData
    {
        $name = data_get($page->meta, 'case_study.client_name');

        return new CaseStudyPublicData(is_string($name) && trim($name) !== '' ? $name : null);
    }
}
