<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @template TDeclaringModel of Model
 *
 * @phpstan-require-extends Model
 *
 * @property int $id
 * @property int|null $parent_id
 * @property int $site_id
 * @property int|null $blueprint_id
 * @property string $name
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Blueprint|null $blueprint
 * @property Layout|null $layout
 * @property Site $site
 * @property Collection<int, Page> $ancestors
 * @property int $children_count
 * @property PageUrl|null $pageUrl
 * @property Collection<int, PageUrl> $pageUrls
 * @property Translation|null $translation
 * @property Collection<int, Translation> $translations
 *
 * @method HasMany<Page, TDeclaringModel> ancestors()
 * @method HasMany<Page, TDeclaringModel> descendants()
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
