<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MediaLibraryImageColumnParent extends Model
{
    /** @use HasFactory<Factory<MediaLibraryImageColumnParent>> */
    use HasFactory;

    protected $table = 'media_column_parents';

    /** @return HasMany<MediaLibraryImageColumnOwner, $this> */
    public function gallery(): HasMany
    {
        return $this->hasMany(MediaLibraryImageColumnOwner::class, 'parent_id');
    }
}
