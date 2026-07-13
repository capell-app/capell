<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Models;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int|null $preferred_admin_language_id
 */
class User extends Authenticatable implements FilamentUser, HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasImpersonation;
    use HasPanelShield;
    use HasRoles;
    use InteractsWithMedia;
    use LogsActivity;
    use Notifiable;

    /** @var Collection<int, int> */
    public Collection $assignedSiteIds;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'bio',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected static string $factory = UserFactory::class;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logAll()
            ->logExcept([
                'email_verified_at',
                'password',
                'remember_token',
                'updated_at',
                'created_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isGlobalAdmin(): bool
    {
        $roleName = (string) config('filament-shield.super_admin.name', 'super_admin');
        $roleId = DB::table('roles')
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->value('id');

        return $roleId !== null && DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->whereNull('team_id')
            ->exists();
    }

    /** @return Collection<int, int> */
    public function getAssignedSiteIds(): Collection
    {
        return $this->assignedSiteIds ?? collect();
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
