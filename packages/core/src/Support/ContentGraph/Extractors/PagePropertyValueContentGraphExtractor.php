<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph\Extractors;

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Illuminate\Database\Eloquent\Model;
use Override;

/** Media, page and term references held in a page's property values. */
final class PagePropertyValueContentGraphExtractor extends PropertyValueContentGraphExtractor
{
    public static function sourceModel(): string
    {
        return Page::class;
    }

    protected function valueModel(): string
    {
        return PagePropertyValue::class;
    }

    protected function ownerColumn(): string
    {
        return 'page_id';
    }

    protected function referenceColumns(): array
    {
        return [
            'media_id' => Media::class,
            'referenced_page_id' => Page::class,
            'term_id' => Term::class,
        ];
    }

    protected function siteId(Model $owner): ?int
    {
        return $this->positiveKey($owner->getAttribute('site_id'));
    }

    /**
     * Only definitions the page's blueprint still attaches are enforced, with that attachment's
     * requirement. A leftover value from a detached definition gates nothing, so it stays weak.
     */
    #[Override]
    protected function requirements(Model $owner, array $definitions): array
    {
        $requirements = array_fill_keys(array_keys($definitions), PropertyRequirement::None);

        if (! $owner instanceof Page) {
            return $requirements;
        }

        foreach (ResolveEffectiveDefinitionsAction::run($owner) as $effective) {
            if (array_key_exists($effective->definitionId, $requirements)) {
                $requirements[$effective->definitionId] = $effective->requirement;
            }
        }

        return $requirements;
    }
}
