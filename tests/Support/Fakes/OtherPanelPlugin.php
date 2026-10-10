<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Override;

final class OtherPanelPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    #[Override]
    public function getId(): string
    {
        return 'existing-plugin';
    }

    #[Override]
    public function register(Panel $panel): void {}

    #[Override]
    public function boot(Panel $panel): void {}
}
