<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class MediaLibraryImageColumnOriginalUrlModel extends Model
{
    /** @use HasFactory<Factory<MediaLibraryImageColumnOriginalUrlModel>> */
    use HasFactory;

    protected $guarded = [];

    protected $table = 'media_column_original_urls';
}
