<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Layout;

use Capell\Core\Enums\LayoutGroupEnum;
use Capell\Core\Models\Layout;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Width;
use Illuminate\Validation\Rules\Unique;

class GroupSelect extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.group'))
            ->native()
            ->options(function (?string $state): array {
                /** @var class-string<Layout> $model */
                $model = Layout::class;

                $options = $model::getGroups();

                if (is_string($state) && $state !== '' && ! isset($options[$state])) {
                    $options[$state] = $state;
                }

                return $options;
            })
            ->selectablePlaceholder(false)
            ->default(LayoutGroupEnum::Default->value)
            ->afterStateHydrated(function (Select $component, ?string $state): void {
                if (blank($state)) {
                    $component->state(LayoutGroupEnum::Default->value);
                }
            })
            ->required()
            ->createOptionForm(fn (): array => [
                TextInput::make('group')
                    ->hiddenLabel()
                    ->autofocus()
                    ->rules(['required', 'lowercase', 'alpha_dash:ascii'])
                    ->unique(table: Layout::class, modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed())
                    ->extraAlpineAttributes(fn (TextInput $component): array => [
                        'x-on:keyup' => <<<'JS'
                            $event.target.value = String($event.target.value.toLowerCase())
                                .normalize('NFKD')
                                .replace(/[\u0300-\u036f]/g, '')
                                .trim()
                                .toLowerCase()
                                .replace(/[^a-z0-9 -]/g, '')
                                .replace(/\s+/g, '-')
                                .replace(/-+/g, '-');
                            $wire.$set('{$component->getStatePath(true)}', $event.target.value)
                        JS
                    ]),
            ])
            ->createOptionAction(
                fn (Action $action): Action => $action
                    ->modalHeading(__('capell-admin::heading.add_group'))
                    ->modalWidth(Width::ScreenLarge)
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.created_successfully',
                            ['name' => __('capell-admin::generic.group')],
                        ),
                    )
                    ->after(function (Action $action): void {
                        $action->success();
                    }),
            )
            ->createOptionUsing(function (Select $component, array $data): string|int {
                $group = $data['group'];
                $options = $component->getOptions();
                if ($group && ! isset($options[$group])) {
                    $options[$group] = $group;
                }

                $component->options($options);
                $component->state($group);

                return $group;
            });
    }
}
