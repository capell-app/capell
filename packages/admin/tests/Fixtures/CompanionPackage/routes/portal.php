<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// A companion package's route file, whose closures depend on Capell's package security.
Route::get('/portal/companion-closure', static fn (): string => 'companion route file served');
