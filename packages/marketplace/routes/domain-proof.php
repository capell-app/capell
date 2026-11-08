<?php

declare(strict_types=1);

use Capell\Marketplace\Http\Controllers\DomainProofChallengeController;
use Capell\Marketplace\Support\DomainProof\DomainProofChallengeStore;
use Illuminate\Support\Facades\Route;

// Public on purpose and outside the web, admin and frontend stacks: capell.app
// fetches it to prove this site controls its host. It reads no session and
// answers only for a token capell.app itself sent this site.
Route::get('.well-known/capell-domain-proof/{token}', DomainProofChallengeController::class)
    ->where('token', DomainProofChallengeStore::TOKEN_PATTERN)
    ->middleware('throttle:30,1')
    ->name('capell-marketplace.domain-proof');
