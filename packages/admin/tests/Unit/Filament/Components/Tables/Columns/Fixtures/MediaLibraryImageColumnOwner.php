<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures;

use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Contracts\Media\MediaContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

final class MediaLibraryImageColumnOwner extends Model implements HasMediaContract
{
    /** @use HasFactory<Factory<MediaLibraryImageColumnOwner>> */
    use HasFactory;

    protected $table = 'media_column_owners';

    /** @param list<string> $urls */
    public function __construct(private readonly array $urls = [])
    {
        parent::__construct();
    }

    public function getMedia(string $collection = 'default'): Collection
    {
        /** @var Collection<int, MediaContract> $media */
        $media = collect(array_map(
            fn (string $url): MediaLibraryImageColumnMedia => new MediaLibraryImageColumnMedia($url),
            $this->urls,
        ));

        return $media;
    }

    public function getFirstMedia(string $collection = 'default'): ?MediaContract
    {
        $url = $this->urls[0] ?? null;

        return is_string($url) && $url !== '' ? new MediaLibraryImageColumnMedia($url) : null;
    }

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        $url = $this->urls[0] ?? '';

        return $url === '' ? '' : sprintf('%s?collection=%s&conversion=%s', $url, $collection, $conversion);
    }

    public function addMediaFromUploadedFile(UploadedFile $file, string $collection = 'default'): MediaContract
    {
        return new MediaLibraryImageColumnMedia($file->getClientOriginalName());
    }

    public function clearMediaCollection(string $collection = 'default'): static
    {
        return $this;
    }
}
