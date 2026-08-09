<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Pages\Fixtures;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Concerns\HasPublishDates;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @implements Pageable<self>
 *
 * Deliberately does not extend Page: this fixture exists to prove
 * DefaultPageTableStatusResolver::resolve() works against any Pageable
 * implementer, not just Page. Pageable's @phpstan-require-extends Page
 * constraint holds for production code but is intentionally violated here.
 */
// @phpstan-ignore class.missingExtends
final class NonPageablePageForResolverTest extends Model implements Pageable, Publishable
{
    use HasFactory;
    use HasPublishDates;
    use SoftDeletes;

    protected $table = 'pages';

    public static function defaultOrdering(): PageOrderEnum
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public static function hasPageHierarchy(): bool
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public static function getDefaultType(?string $group): ?Blueprint
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function shouldLogVisit(): bool
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function getParentUrl(Language $language, bool $fullUrl = false): string
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function pageUrl(): MorphOne
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function pageUrls(): MorphMany
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function canonicalPages(): MorphMany
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function languages(): HasManyThrough
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function translation(): HasOne|MorphOne
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function translations(): HasMany|MorphMany
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function site(): BelongsTo
    {
        throw new LogicException('Not needed by this fixture.');
    }

    public function canonicalPage(): MorphTo
    {
        throw new LogicException('Not needed by this fixture.');
    }
}
