<?php

declare(strict_types=1);

use Capell\Core\Exceptions\MissingMorphedModelException;

describe('MissingMorphedModelException', function (): void {
    it('builds a message with the morph type only', function (): void {
        $exception = new MissingMorphedModelException('article');

        expect($exception->getMessage())->toBe('Unable to find morph model for type: article');
    });

    it('appends a suggestion when one is provided', function (): void {
        $exception = new MissingMorphedModelException('articl', 'article');

        expect($exception->getMessage())
            ->toBe('Unable to find morph model for type: articl; did you mean: article');
    });

    it('omits the suggestion when an empty string is given', function (): void {
        $exception = new MissingMorphedModelException('post', '');

        expect($exception->getMessage())->toBe('Unable to find morph model for type: post');
    });

    it('omits the suggestion when null is given', function (): void {
        $exception = new MissingMorphedModelException('page');

        expect($exception->getMessage())->toBe('Unable to find morph model for type: page');
    });

    it('extends Exception', function (): void {
        expect(new MissingMorphedModelException('type'))->toBeInstanceOf(Exception::class);
    });
});
