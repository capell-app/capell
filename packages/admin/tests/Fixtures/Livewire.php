<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Livewire\Component;

class Livewire extends Component implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function make(): self
    {
        return new self;
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }
}
