<?php

declare(strict_types=1);

use Capell\Admin\Support\Auth\EmailMasker;

it('masks the local part, domain name and keeps the tld', function (): void {
    expect((new EmailMasker)->mask('alice@example.com'))->toBe('a***e@e*****e.com');
});

it('masks short single-character segments fully', function (): void {
    expect((new EmailMasker)->mask('a@b.io'))->toBe('*@*.io');
});

it('masks two-character segments to a leading char plus star', function (): void {
    expect((new EmailMasker)->mask('ab@cd.io'))->toBe('a*@c*.io');
});
