<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\TextInput;
use Override;

class NameInput extends TextInput
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.name'))
            ->required()
            ->hint(__('capell-admin::generic.internal'))
            ->autofocus(fn (string $operation): bool => in_array($operation, ['create', 'createOption', 'replicate'], true));
    }

    public function withTitleUpdater(): self
    {
        return $this->afterStateUpdatedJs(fn (string $operation): string => <<<JS
            if (\$state) {
                let translations = \$get('translations');
                const key = Object.keys(translations)[0];
                if (
                    Object.values(translations).length
                    && (
                        ['create', 'createOption', 'replicate'].includes('{$operation}')
                        || !translations[key].title
                    )
                ) {
                    \$set('translations.' + key + '.title', \$state);
                }
            }
        JS);
    }
}
