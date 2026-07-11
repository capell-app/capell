<?php

declare(strict_types=1);

namespace Capell\Core\Models\Contracts;

use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @phpstan-require-extends Model
 */
interface Translatable
{
    public function getPrimaryLanguage(): ?Language;

    public function languages(): HasManyThrough;

    public function translation(): HasOne|MorphOne;

    public function translations(): HasMany|MorphMany;
}
