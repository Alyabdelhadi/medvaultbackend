<?php

namespace App\Models;

use App\Support\AdminModules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminGroup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'for_labs' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allows(string $module, string $action = 'view'): bool
    {
        if ($this->for_labs && AdminModules::isSiteWide($module)) {
            return false;
        }

        return in_array($action, (array) (($this->permissions ?? [])[$module] ?? []), true);
    }
}
