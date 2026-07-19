<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface RuntimeRefreshWarmer
{
    public const string TAG = 'capell.runtime-refresh.warmer';

    public function label(): string;

    public function warm(): void;
}
