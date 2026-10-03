<?php

declare(strict_types=1);

use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;

it('protects retained dependencies even when a component validator allows deletion', function (string $type): void {
    test()->actingAsAdmin();
    [$record, $dependent] = match ($type) {
        'layout' => [$layout = Layout::factory()->createOne(), Page::factory()->createOne(['layout_id' => $layout->id])],
        'theme' => [$theme = Theme::factory()->createOne(), Site::factory()->createOne(['theme_id' => $theme->id])],
        'blueprint' => [$blueprint = Blueprint::factory()->page()->createOne(), Page::factory()->createOne(['blueprint_id' => $blueprint->id])],
        'language' => [$language = Language::factory()->createOne(), Site::factory()->createOne(['language_id' => $language->id])],
        'page' => [$page = Page::factory()->createOne(), Page::factory()->canonicalPage($page)->createOne()],
        default => throw new InvalidArgumentException('Unknown deletion dependency fixture: ' . $type),
    };
    $dependent->delete();
    $record->delete();

    $validator = new class implements ValidatesDelete
    {
        #[Override]
        public function validateDelete(Model $record): bool
        {
            return true;
        }
    };

    expect(ValidateForceDeleteAction::run($record, $validator))->toBeFalse();
})->with(['layout', 'theme', 'blueprint', 'language', 'page']);
