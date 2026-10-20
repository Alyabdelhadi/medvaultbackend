<?php

namespace App\Models;

use App\Support\AdminModules;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * There is deliberately no 'hashed' cast on password. Imported accounts hold
 * WordPress hashes ($wp$2y$…, $P$…), which Laravel does not recognise as
 * hashed, so the cast would hash the hash and lock every one of them out.
 * Set passwords with Hash::make().
 */
#[Hidden(['password', 'remember_token', 'api_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'administrator';

    public const ROLE_LAB_MANAGER = 'lab_manager';

    public const ROLE_SUBSCRIBER = 'subscriber';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'managed_lab_id' => 'integer',
            'admin_group_id' => 'integer',
            'owner_lab_id' => 'integer',
            'dg_lab_id' => 'integer',
        ];
    }

    /** P, D or G. An unset type has always meant a patient. */
    public function functionType(): string
    {
        return $this->function_type ?: 'P';
    }

    public function labAccounts(): HasMany
    {
        return $this->hasMany(LabAccount::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function adminGroup(): BelongsTo
    {
        return $this->belongsTo(AdminGroup::class);
    }

    public function isAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * The group this account's permissions come from. A lab manager imported
     * from WordPress has none and keeps what the plugin gave it, as an
     * unsaved lab group.
     */
    public function effectiveGroup(): ?AdminGroup
    {
        if ($this->admin_group_id) {
            return $this->adminGroup;
        }

        return $this->role === self::ROLE_LAB_MANAGER
            ? new AdminGroup(['name' => 'Lab Manager', 'permissions' => AdminModules::LAB_MANAGER_DEFAULTS, 'for_labs' => true])
            : null;
    }

    /**
     * Whether this account may perform $action on an admin page. A lab
     * group's account without a lab gets nothing: unscoped, it would see
     * every lab's patients.
     */
    public function canAdmin(string $module, string $action = 'view'): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        $group = $this->effectiveGroup();
        if ($group === null || ($group->for_labs && (int) $this->managed_lab_id <= 0)) {
            return false;
        }

        return $group->allows($module, $action);
    }

    /** Whether this account can sign in to the admin at all. */
    public function hasAdminAccess(): bool
    {
        return $this->firstAdminRoute() !== null;
    }

    /** The first page this account may open, where sign-in lands. */
    public function firstAdminRoute(): ?array
    {
        foreach (AdminModules::all() as $key => $module) {
            if ($this->canAdmin($key, 'view')) {
                return $module['route'];
            }
        }

        return null;
    }

    /** Admin accounts: never listed with the app's patients. */
    public function isStaff(): bool
    {
        return $this->isAdministrator() || $this->role === self::ROLE_LAB_MANAGER || $this->admin_group_id !== null;
    }
}
