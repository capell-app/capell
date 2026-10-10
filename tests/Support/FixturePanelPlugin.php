<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Override;

final class FixturePanelPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    #[Override]
    public function getId(): string
    {
        return 'fixture-plugin';
    }

    #[Override]
    public function register(Panel $panel): void {}

    #[Override]
    public function boot(Panel $panel): void {}
}
