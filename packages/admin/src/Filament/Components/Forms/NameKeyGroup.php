<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Core\Support\Slug\SlugGenerator;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Group;
use Filament\Schemas\JsContent;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;

class NameKeyGroup
{
    public static function make(
        ?Closure $modifyKey = null,
        ?Closure $modifyName = null,
    ): Group {
        $className = 'name-key-group';
        $nameInput = NameInput::make('name')
            ->belowContent(
                fn (string $operation): ?Schema => in_array($operation, ['edit', 'editOption'], true)
                    ? Schema::start([
                        __('capell-admin::generic.key_label'),
                        Action::make('toggle_key')
                            ->label(function (): Htmlable {
                                $noKeySetText = __('capell-admin::generic.no_key_set');

                                return JsContent::make(
                                    <<<JS
                                    \$get('key') || '{$noKeySetText}'
                                JS
                                );
                            })
                            ->link()
                            ->color('gray')
                            ->icon(Heroicon::OutlinedPencil)
                            ->iconPosition(IconPosition::After)
                            ->alpineClickHandler(
                                fn (): string => <<<JS
                                        \$set('edit_key', ! \$get('edit_key'));
                                        let containedEl = \$root;
                                        while (! containedEl.classList.contains('{$className}')) {
                                            containedEl = containedEl.parentElement;
                                        }
                                        setTimeout(() => {
                                            const keyInput = containedEl.querySelector('input[x-ref=\'keyInput\']');
                                            if (keyInput && \$get('edit_key') === true) {
                                                keyInput.focus();
                                            }
                                        }, 100);
                                    JS
                            ),
                    ])
                        ->dense()
                    : null,
            )
            ->afterStateUpdatedJs(function (string $operation): string {
                if (! in_array($operation, ['create', 'createOption', 'replicate'], true)) {
                    return '';
                }

                return SlugGenerator::slugifyState("\$state ?? ''", 'key');
            });

        if ($modifyName instanceof Closure) {
            $nameInput = $modifyName($nameInput);
        }

        $keyInput = KeyTextInput::make()
            ->extraInputAttributes(['x-ref' => 'keyInput'])
            ->visibleJs(
                fn (string $operation): string => match ($operation) {
                    'edit', 'editOption' => <<<'JS'
                            $get('edit_key') === true
                        JS,
                    default => <<<'JS'
                            true
                        JS,
                },
            );

        if ($modifyKey instanceof Closure) {
            $keyInput = $modifyKey($keyInput);
        }

        return Group::make()
            ->extraAttributes([
                'class' => $className,
                'x-data' => '{}',
                'x-on:expand' => '$set(\'edit_key\', true);',
            ])
            ->dense()
            ->columnSpan(
                fn (string $operation): int => in_array($operation, ['create', 'createOption', 'replicate'], true) ? 2 : 1,
            )
            ->columns(
                fn (string $operation): int => in_array($operation, ['create', 'createOption', 'replicate'], true) ? 2 : 1,
            )
            ->schema([
                $nameInput,

                Hidden::make('edit_key')
                    ->dehydrated(false)
                    ->default(true)
                    ->visible(function (): bool {
                        $user = auth()->user();

                        if (! $user instanceof Authenticatable) {
                            return false;
                        }

                        return $user->hasRole(Utils::getSuperAdminName());
                    }),

                $keyInput,
            ]);
    }
}
