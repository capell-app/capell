<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph\Extractors;

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Database\Eloquent\Model;

/** Media, page and term references held in a term's property values. */
final class TermPropertyValueContentGraphExtractor extends PropertyValueContentGraphExtractor
{
    public static function sourceModel(): string
    {
        return Term::class;
    }

    protected function valueModel(): string
    {
        return TermPropertyValue::class;
    }

    protected function ownerColumn(): string
    {
        return 'term_id';
    }

    protected function referenceColumns(): array
    {
        return [
            'media_id' => Media::class,
            'referenced_page_id' => Page::class,
            'referenced_term_id' => Term::class,
        ];
    }

    protected function siteId(Model $owner): ?int
    {
        return $this->positiveKey(
            Taxonomy::query()->whereKey($owner->getAttribute('taxonomy_id'))->value('site_id'),
        );
    }
}
