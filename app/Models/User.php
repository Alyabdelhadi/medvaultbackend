<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
