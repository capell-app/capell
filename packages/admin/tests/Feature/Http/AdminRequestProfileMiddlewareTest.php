<?php

declare(strict_types=1);

use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class);

it('does not add admin profiling headers by default', function (): void {
    $this->actingAsAdmin();

    $this->get('/admin')
        ->assertOk()
        ->assertHeaderMissing('X-Capell-Admin-Queries')
        ->assertHeaderMissing('X-Capell-Admin-Sql-Ms')
        ->assertHeaderMissing('X-Capell-Admin-Duration-Ms')
        ->assertHeaderMissing('X-Capell-Admin-Memory-Mb')
        ->assertHeaderMissing('X-Capell-Admin-Response-Bytes');
});

it('adds admin profiling headers for authenticated local admin requests when requested', function (): void {
    $this->actingAsAdmin();

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('X-Capell-Admin-Profile', '1')
        ->get('/admin')
        ->assertOk()
        ->assertHeader('X-Capell-Admin-Queries')
        ->assertHeader('X-Capell-Admin-Sql-Ms')
        ->assertHeader('X-Capell-Admin-Duration-Ms')
        ->assertHeader('X-Capell-Admin-Memory-Mb')
        ->assertHeader('X-Capell-Admin-Response-Bytes')
        ->assertHeader('Server-Timing');
});

it('does not profile unauthenticated admin requests', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('X-Capell-Admin-Profile', '1')
        ->get('/admin/login')
        ->assertOk()
        ->assertHeaderMissing('X-Capell-Admin-Queries');
});
