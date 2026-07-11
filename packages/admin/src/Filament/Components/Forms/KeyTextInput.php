<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;
use Override;

class KeyTextInput extends TextInput
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.key'))
            ->hiddenLabel(fn (string $operation): bool => ! in_array(
                $operation,
                ['create', 'createOption'],
                true,
            ))
            ->prefix(function (self $component, string $operation): ?string {
                if (in_array($operation, ['create', 'createOption'], true)) {
                    return null;
                }

                $label = $component->getLabel();

                return $label instanceof Htmlable ? $label->toHtml() : (string) $label;
            })
            ->placeholder(__('capell-admin::generic.key_placeholder'))
            ->alphaDash()
            ->required()
            ->maxLength(128);
    }

    public static function getDefaultName(): ?string
    {
        return 'key';
    }
}
