<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * @property Schema $form
 */
class Livewire extends Component implements HasForms
{
    use InteractsWithForms;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = null;

    public static function make(): self
    {
        return new self;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }
}
