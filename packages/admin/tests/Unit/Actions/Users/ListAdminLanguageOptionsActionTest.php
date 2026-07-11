<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\ListAdminLanguageOptionsAction;
use Capell\Core\Models\Language;

it('returns enabled language records ordered for admin language selection', function (): void {
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->create(['status' => true]);
    $english = Language::factory()->english(order: 1)->create(['status' => true]);

    Language::factory()->french(order: 3)->create(['status' => false]);

    expect(ListAdminLanguageOptionsAction::run()->all())->toBe([
        $english->getKey() => 'English',
        $welsh->getKey() => 'Welsh',
    ]);
});
