<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Data\AdminHelpEntryData;
use Capell\Admin\Support\AdminHelpRegistry;
use Illuminate\Support\Facades\Route;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static ?AdminHelpEntryData run() */
final class ResolveAdminHelpAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly AdminHelpRegistry $registry) {}

    public function handle(): ?AdminHelpEntryData
    {
        $route = Route::currentRouteName();

        return $route === null ? null : $this->registry->get($route);
    }
}
