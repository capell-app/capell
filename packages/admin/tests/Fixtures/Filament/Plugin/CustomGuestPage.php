<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Pages\SimplePage;
use Override;
use Symfony\Component\HttpFoundation\Response;

final class CustomGuestPage extends SimplePage
{
    public const string BODY = 'Custom guest page served';

    // Replaces Livewire's trait entrypoint; the response isolates route security from page rendering.
    #[Override]
    public function __invoke(): Response
    {
        return response(self::BODY);
    }
}
