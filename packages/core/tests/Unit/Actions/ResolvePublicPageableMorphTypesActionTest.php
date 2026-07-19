<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

final class UnavailableModel extends Model
{
    use HasFactory;

    protected $table = 'unavailable_pageables';
}

it('returns aliases and model classes only for pageable morphs', function (): void {
    expect(ResolvePublicPageableMorphTypesAction::run())
        ->toContain('page', Page::class)
        ->not->toContain('user', User::class);
});

it('detects models whose package table is unavailable', function (): void {
    $action = new ResolvePublicPageableMorphTypesAction;
    $method = new ReflectionMethod($action, 'hasBackingTable');

    expect($method->invoke($action, UnavailableModel::class))->toBeFalse();
});
