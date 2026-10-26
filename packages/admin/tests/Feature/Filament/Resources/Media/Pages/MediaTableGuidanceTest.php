<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('media');

beforeEach(function (): void {
    test()->actingAsAdmin();
    Storage::fake('local');
    Storage::fake('public');
    config()->set('capell.media.model', CapellMedia::class);
    config()->set('media-library.media_model', CapellMedia::class);
});

it('matches every concurrent media health issue in the table query', function (): void {
    $owner = Site::factory()->createOne();
    $media = CapellMedia::factory()->model($owner)->createOne(['collection_name' => 'uploads']);

    $duplicate = CapellMedia::factory()->model($owner)->createOne(['collection_name' => 'uploads', 'size' => $media->size]);
    Storage::disk($media->disk)->put($media->getPathRelativeToRoot(), 'same image bytes');
    Storage::disk($duplicate->disk)->put($duplicate->getPathRelativeToRoot(), 'same image bytes');

    foreach (['missing_alt', 'missing_rights', 'duplicate', 'unused'] as $issue) {
        Livewire::test(ListMedia::class)
            ->filterTable('health', $issue)
            ->assertCanSeeTableRecords([$media]);
    }

    Livewire::test(ListMedia::class)
        ->filterTable('health', 'healthy')
        ->assertCanNotSeeTableRecords([$media]);
});

it('offers a generated public download link while keeping file name copying separate', function (): void {
    $site = Site::factory()->createOne();
    $public = $site->addMedia(UploadedFile::fake()->createWithContent('brochure.pdf', '%PDF brochure'))
        ->toMediaCollection('uploads', 'public');
    $private = $site->addMedia(UploadedFile::fake()->createWithContent('private.pdf', '%PDF private'))
        ->toMediaCollection('uploads', 'local');

    $component = Livewire::test(ListMedia::class)
        ->assertTableActionVisible('open-file', $public)
        ->assertTableActionHasUrl('open-file', $public->getUrl(), $public)
        ->assertTableActionVisible('copy-public-link', $public)
        ->assertTableActionHidden('open-file', $private)
        ->assertTableActionHidden('copy-public-link', $private)
        ->assertDontSee($private->getUrl());

    $column = $component->instance()->getTable()->getColumn('name');
    assert($column instanceof TextColumn);
    $column->record($public);
    expect($column->getCopyableState($public->name))->toBe($public->file_name);
});

it('keeps foreign site media out of concurrent issue filters', function (): void {
    $assigned = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $visible = CapellMedia::factory()->model($assigned)->createOne(['collection_name' => 'uploads']);
    $hidden = CapellMedia::factory()->model($foreign)->createOne(['collection_name' => 'uploads']);
    test()->actingAs(ScopedAdminUser::make(collect([$assigned->id])));

    foreach (['missing_alt', 'missing_rights', 'unused', 'healthy'] as $issue) {
        $table = Livewire::test(ListMedia::class)->filterTable('health', $issue)
            ->assertCanNotSeeTableRecords([$hidden]);
        if ($issue !== 'healthy') {
            $table->assertCanSeeTableRecords([$visible]);
        } else {
            $table->assertCanNotSeeTableRecords([$visible]);
        }
    }
});

it('keeps the media table accessible when public file visibility metadata fails', function (): void {
    $site = Site::factory()->createOne();
    $affected = $site->addMedia(UploadedFile::fake()->createWithContent('affected.pdf', '%PDF affected'))
        ->toMediaCollection('uploads', 'public');
    $unrelated = $site->addMedia(UploadedFile::fake()->createWithContent('unrelated.pdf', '%PDF unrelated'))
        ->toMediaCollection('uploads', 'public');
    $disk = Storage::disk('public');
    $affectedPath = $affected->getPathRelativeToRoot();
    $storage = Mockery::mock($disk)->makePartial();
    $storage->shouldReceive('exists')->with($affectedPath)->andReturnTrue();
    $storage->shouldReceive('getVisibility')->with($affectedPath)
        ->andThrow(UnableToRetrieveMetadata::visibility($affectedPath, 'secret storage credentials'));
    Storage::set('public', $storage);
    $logger = Log::spy();

    Livewire::test(ListMedia::class)
        ->assertCanSeeTableRecords([$affected, $unrelated])
        ->assertTableActionHidden('open-file', $affected)
        ->assertTableActionHidden('copy-public-link', $affected)
        ->assertTableActionVisible('public-link-unavailable', $affected)
        ->assertTableActionHasUrl('open-file', $unrelated->getUrl(), $unrelated)
        ->assertDontSee($affected->getUrl())
        ->assertDontSee('secret storage credentials');

    $logger->shouldHaveReceived('warning')->with('Unable to resolve public media link.', [
        'media_id' => $affected->id,
        'disk' => 'public',
        'exception' => UnableToRetrieveMetadata::class,
    ]);
});

it('reuses public link lookups across all five media table callbacks for several rows', function (): void {
    $site = Site::factory()->createOne();
    $records = collect(range(1, 3))->map(fn (int $number) => $site
        ->addMedia(UploadedFile::fake()->createWithContent(sprintf('table-%d.pdf', $number), '%PDF table'))
        ->toMediaCollection('uploads', 'public'));
    test()->actingAs(ScopedAdminUser::make(collect([$site->id])));
    $component = Livewire::test(ListMedia::class)->assertCanSeeTableRecords($records);
    $table = $component->instance()->getTable();
    $open = $table->getAction('open-file');
    $copy = $table->getAction('copy-public-link');
    $unavailable = $table->getAction('public-link-unavailable');
    assert($open instanceof Action && $copy instanceof Action && $unavailable instanceof Action);

    $disk = Storage::disk('public');
    $storage = Mockery::mock($disk)->makePartial();
    foreach ($records as $record) {
        $storage->shouldReceive('exists')->with($record->getPathRelativeToRoot())->once()->andReturnTrue();
        $storage->shouldReceive('getVisibility')->with($record->getPathRelativeToRoot())->once()->andReturn('public');
    }

    Storage::set('public', $storage);

    $previousRequest = request();
    try {
        app()->instance('request', Request::create('/admin'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($records as $record) {
            $open->record($record);
            $copy->record($record);
            $unavailable->record($record);
            for ($evaluation = 0; $evaluation < 5; $evaluation++) {
                expect($open->isVisible())->toBeTrue()
                    ->and($open->getUrl())->toBe($record->getUrl())
                    ->and($copy->isVisible())->toBeTrue()
                    ->and($copy->getAlpineClickHandler())->toBe('navigator.clipboard.writeText(' . Js::from($record->getUrl()) . ')')
                    ->and($unavailable->isVisible())->toBeFalse();
            }
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        expect($queries)->toHaveCount(3);
    } finally {
        DB::disableQueryLog();
        app()->instance('request', $previousRequest);
    }
});
