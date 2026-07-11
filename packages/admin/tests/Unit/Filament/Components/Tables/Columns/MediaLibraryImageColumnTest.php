<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Columns\MediaLibraryImageColumn;
use Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures\MediaLibraryImageColumnMedia;
use Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures\MediaLibraryImageColumnOriginalUrlModel;
use Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures\MediaLibraryImageColumnOwner;
use Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures\MediaLibraryImageColumnParent;
use Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures\TestableMediaLibraryImageColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

it('resolves media URLs from the record and nested related records', function (): void {
    $owner = new MediaLibraryImageColumnOwner(['/hero/card.jpg']);

    expect(MediaLibraryImageColumn::make('hero')
        ->collection('cards')
        ->conversion('thumb')
        ->record($owner)
        ->getState())->toBe('/hero/card.jpg?collection=cards&conversion=thumb');

    $parent = new MediaLibraryImageColumnParent;
    $parent->setRelation('gallery', new EloquentCollection([
        new MediaLibraryImageColumnOwner(['/gallery/one.jpg']),
        new MediaLibraryImageColumnOwner(['']),
        new MediaLibraryImageColumnOwner(['/gallery/two.jpg']),
    ]));

    expect(MediaLibraryImageColumn::make('gallery.image')
        ->conversion('small')
        ->record($parent)
        ->getState())->toBe([
            '/gallery/one.jpg?collection=image&conversion=small',
            '/gallery/two.jpg?collection=image&conversion=small',
        ]);
});

it('normalises media contracts models and collections into renderable image states', function (): void {
    $column = TestableMediaLibraryImageColumn::make('image')->conversion('thumb');
    $mediaModel = new MediaLibraryImageColumnOriginalUrlModel(['original_url' => '/model/original.jpg']);

    $state = $column->exposeNormaliseState(new Collection([
        new MediaLibraryImageColumnMedia('/media/original.jpg'),
        $mediaModel,
        '',
        null,
    ]));

    expect($state)->toBe([
        '/media/original.jpg?conversion=thumb',
        '/model/original.jpg',
    ]);
});

it('eager loads only resolvable media owner relationships', function (): void {
    $query = MediaLibraryImageColumnParent::query();

    /** @var Builder<Model> $query */
    MediaLibraryImageColumn::make('gallery.image')->applyEagerLoading($query);

    expect(array_keys($query->getEagerLoads()))->toBe(['gallery']);

    $disabledQuery = MediaLibraryImageColumnParent::query();
    /** @var Builder<Model> $disabledQuery */
    MediaLibraryImageColumn::make('gallery.image')
        ->autoEagerLoadRelation(false)
        ->applyEagerLoading($disabledQuery);

    expect($disabledQuery->getEagerLoads())->toBe([]);
});
