<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @template TDeclaringModel of Model
 *
 * @phpstan-require-extends Page
 */
interface Pageable
{
    public static function defaultOrdering(): PageOrderEnum;

    public static function hasPageHierarchy(): bool;

    public static function getDefaultType(?string $group): ?Blueprint;

    public function shouldLogVisit(): bool;

    public function getParentUrl(Language $language, bool $fullUrl = false): string;

    /** @return MorphOne<PageUrl, TDeclaringModel> */
    public function pageUrl(): MorphOne;

    /** @return MorphMany<PageUrl, TDeclaringModel> */
    public function pageUrls(): MorphMany;

    /** @return MorphMany<Page, TDeclaringModel> */
    public function canonicalPages(): MorphMany;

    /** @return HasManyThrough<Language, Translation, TDeclaringModel> */
    public function languages(): HasManyThrough;

    /** @return HasOne<Translation, TDeclaringModel>|MorphOne<Translation, TDeclaringModel> */
    public function translation(): HasOne|MorphOne;

    /** @return HasMany<Translation, TDeclaringModel>|MorphMany<Translation, TDeclaringModel> */
    public function translations(): HasMany|MorphMany;

    /** @return BelongsTo<Site, TDeclaringModel> */
    public function site(): BelongsTo;

    /** @return MorphTo<Model, TDeclaringModel> */
    public function canonicalPage(): MorphTo;
}
