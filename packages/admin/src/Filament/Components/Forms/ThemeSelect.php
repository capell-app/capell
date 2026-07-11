<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Filament\Resources\Themes\Schemas\ThemeForm;
use Capell\Core\Models\Theme;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Override;

class ThemeSelect extends Select
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.theme'))
            ->relationship(
                name: 'theme',
                titleAttribute: 'name',
                modifyQueryUsing: fn (Builder $query) => $query->enabled()->ordered(),
            )
            ->helperText(function (?string $state, Select $component): ?HtmlString {
                if (in_array($state, [null, '', '0'], true)) {
                    return null;
                }

                $theme = $component->getSelectedRecord();

                if (! $theme instanceof Theme) {
                    return null;
                }

                $admin = is_array($theme->admin) ? $theme->admin : [];
                $image = is_string($admin['image'] ?? null) && $admin['image'] !== ''
                    ? $admin['image']
                    : $theme->readyGeneratedImage();

                if ($image === null) {
                    return null;
                }

                return new HtmlString(
                    '<img src="' . Storage::disk('public')->url($image) . '" alt="' . e($theme->name) . '" class="w-full h-auto max-h-32 aspect-square object-cover rounded-lg shadow-md" />',
                );
            })
            ->autoDefault();
    }

    public function withCreateForm(): self
    {
        return $this->editOptionForm(fn (Schema $schema): Schema => ThemeForm::configure($schema))
            ->createOptionAction(
                fn (Action $action): Action => $action
                    ->modalHeading(__('capell-admin::form.theme'))
                    ->modalWidth(Width::ScreenLarge)
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.created_successfully',
                            ['name' => $this->modalHeadingText($action)],
                        ),
                    )
                    ->after(function (Action $action): void {
                        $action->success();
                    }),
            );
    }

    public function withEditForm(): self
    {
        return $this->fillEditOptionActionFormUsing(
            fn (Select $component): array => $component->getSelectedRecord()?->attributesToArray() ?? [],
        )
            ->editOptionForm(fn (Schema $schema): Schema => ThemeForm::configure($schema))
            ->editOptionAction(
                fn (Action $action): Action => $action
                    ->modalHeading(__('capell-admin::form.theme'))
                    ->modalWidth(Width::ScreenLarge)
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.updated_successfully',
                            ['name' => $this->modalHeadingText($action)],
                        ),
                    )
                    ->after(function (Action $action): void {
                        $action->success();
                    }),
            );
    }

    private function modalHeadingText(Action $action): string
    {
        $heading = $action->getModalHeading();

        return $heading instanceof Htmlable ? $heading->toHtml() : $heading;
    }
}
