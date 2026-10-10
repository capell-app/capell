<?php

declare(strict_types=1);

it('enables the local two factor bypass by default', function (): void {
    expect(config('capell.auth.skip_two_factor_when_local'))->toBeTrue();
});
