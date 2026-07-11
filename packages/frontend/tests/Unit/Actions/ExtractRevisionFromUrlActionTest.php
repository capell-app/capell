<?php

declare(strict_types=1);

use Capell\Frontend\Actions\ExtractRevisionFromUrlAction;

it('extracts revision id from url path and query when present', function (): void {
    $rev = ExtractRevisionFromUrlAction::run('/en/page?revision=123');
    expect($rev)->toBe(123);

    $none = ExtractRevisionFromUrlAction::run('/en/page');
    expect($none)->toBeNull();
});
