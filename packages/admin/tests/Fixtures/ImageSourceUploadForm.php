<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Capell\Admin\Filament\Components\Forms\ImageSourcePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;

/** A form holding only the image source picker, limited to its upload source. */
final class ImageSourceUploadForm extends Component implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['meta' => ['image_source' => ['type' => 'upload']]]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageSourcePicker::make('image')
                    ->allowedSources('upload_only')
                    ->sourceStatePath('meta.image_source'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
