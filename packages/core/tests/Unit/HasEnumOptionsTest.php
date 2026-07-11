<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;

describe('HasEnumOptions', function (): void {
    it('returns all options as value => label pairs', function (): void {
        expect(BlueprintSubjectEnum::options())->toBe([
            'page' => 'Page',
            'site' => 'Site',
            'theme' => 'Theme',
        ]);
    });

    it('includes every enum case as a key', function (): void {
        $expectedValues = array_map(fn (BlueprintSubjectEnum $case): string => $case->value, BlueprintSubjectEnum::cases());

        expect(array_keys(BlueprintSubjectEnum::options()))->toBe($expectedValues);
    });

    it('returns non-empty options', function (): void {
        expect(BlueprintSubjectEnum::options())->not->toBeEmpty();
    });

    it('returns the same array on repeated calls via static cache', function (): void {
        $first = BlueprintSubjectEnum::options();
        $second = BlueprintSubjectEnum::options();

        expect($first)->toBe($second);
    });
});
