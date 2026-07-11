<?php

declare(strict_types=1);

/**
 * @autor awcodes - https://github.com/awcodes/sink/blob/main/src/FormBuilder/FixedWidthSidebar.php
 */

namespace Capell\Admin\Filament\Components\Forms;

use Closure;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Override;

class FixedWidthSidebar extends Group
{
    /** @var array<int, mixed>|Closure|null */
    protected array|Closure|null $mainSchema = null;

    /** @var array<int, mixed>|Closure|null */
    protected array|Closure|null $sidebarSchema = null;

    protected bool $sidebarContained = false;

    protected bool $mainContained = false;

    protected string $view = 'capell-admin::components.layouts.fixed-width-sidebar';

    protected function setUp(): void
    {
        parent::setUp();

        $this->columnSpanFull();
    }

    #[Override]
    public function getDefaultChildSchemas(): array
    {
        return [
            'main' => Schema::make($this->getLivewire())
                ->parentComponent($this)
                ->components(
                    $this->mainContained
                        ? [
                            Section::make()
                                ->gridContainer()
                                ->columns(['default' => 1, '@lg' => 2])
                                ->components($this->getMainSchema()),
                        ]
                        : $this->getMainSchema(),
                ),
            'sidebar' => Schema::make($this->getLivewire())
                ->parentComponent($this)
                ->components(
                    $this->sidebarContained
                        ? [
                            Section::make()
                                ->gridContainer()
                                ->columns(['default' => 1, '@lg' => 2])
                                ->components($this->getSidebarSchema()),
                        ]
                        : $this->getSidebarSchema(),
                ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function getMainSchema(): array
    {
        return $this->evaluate($this->mainSchema) ?? [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getSidebarSchema(): array
    {
        return $this->evaluate($this->sidebarSchema) ?? [];
    }

    /**
     * @param  array<int, mixed>|Closure  $schema
     */
    public function mainSchema(array|Closure $schema, ?bool $contained = null): static
    {
        $this->mainSchema = $schema;

        if ($contained !== null) {
            $this->mainContained = $contained;
        }

        return $this;
    }

    /**
     * @param  array<int, mixed>|Closure  $schema
     */
    public function sidebarSchema(array|Closure $schema, ?bool $contained = null): static
    {
        $this->sidebarSchema = $schema;

        if ($contained !== null) {
            $this->sidebarContained = $contained;
        }

        return $this;
    }
}
