<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Actions\Fixtures;

use Capell\Tests\Fixtures\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReplacementMediaOwner extends User
{
    protected $table = 'users';

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('single')->singleFile()->storeConversionsOnDisk('conversions');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->queued()->width(16)->height(16);
    }
}
