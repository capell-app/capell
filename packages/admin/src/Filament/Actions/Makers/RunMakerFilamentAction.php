<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Makers;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Support\Makers\MakerSafety;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use RuntimeException;
use Throwable;

class RunMakerFilamentAction
{
    public static function make(string $maker): Action
    {
        $definition = resolve(MakerRegistryInterface::class)->get($maker)->definition();
        $actionName = 'maker_' . str($maker)->replace('.', '_')->toString();

        return Action::make($actionName)
            ->label($definition->label)
            ->icon($definition->icon)
            ->schema([
                TextInput::make('name')
                    ->label(__('capell-admin::form.name'))
                    ->required(),
                Checkbox::make('dryRun')
                    ->label(__('capell-admin::generic.preview_only'))
                    ->default(config('capell.diagnostics.readonly_preview', true)),
                Checkbox::make('force')
                    ->label(__('capell-admin::generic.overwrite_existing_files'))
                    ->default(false),
            ])
            ->visible(fn (): bool => ! $definition->supportsPhpWrites || resolve(MakerSafety::class)->current()->phpWritesAllowed)
            ->action(function (array $data) use ($maker): void {
                try {
                    $result = RunMakerAction::run(new MakerInputData(
                        maker: $maker,
                        values: ['name' => $data['name'] ?? ''],
                        dryRun: (bool) ($data['dryRun'] ?? true),
                        force: (bool) ($data['force'] ?? false),
                        databaseWrites: false,
                    ));

                    Notification::make()
                        ->title($result->successful ? __('capell-admin::generic.maker_completed') : __('capell-admin::generic.maker_failed'))
                        ->body($result->files->pluck('path')->implode(PHP_EOL))
                        ->status($result->successful ? 'success' : 'danger')
                        ->send();
                } catch (Throwable $throwable) {
                    Notification::make()
                        ->title(__('capell-admin::generic.maker_failed'))
                        ->body($throwable->getMessage())
                        ->danger()
                        ->send();

                    throw new RuntimeException($throwable->getMessage(), $throwable->getCode(), previous: $throwable);
                }
            });
    }
}
