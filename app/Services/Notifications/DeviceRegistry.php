<?php

namespace App\Services\Notifications;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FCM device tokens per account, from medvault_register_device_token().
 *
 * A token belongs to one account at a time: a handset that signs into a
 * second account must stop receiving the first one's result alerts, which
 * carry request numbers. Each account keeps its ten most recent devices.
 */
final class DeviceRegistry
{
    public const MAX_PER_USER = 10;

    public function register(User $user, string $token, string $platform): void
    {
        DB::transaction(function () use ($user, $token, $platform) {
            // Moves the token off whoever held it before.
            Device::where('token', $token)->where('user_id', '!=', $user->id)->delete();

            $device = Device::firstOrNew(['token' => $token]);
            $device->forceFill(['user_id' => $user->id, 'platform' => $platform])->touch();

            $keep = Device::where('user_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')
                ->limit(self::MAX_PER_USER)->pluck('id');
            Device::where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
        });
    }

    public function remove(User $user, string $token): void
    {
        Device::where('user_id', $user->id)->where('token', $token)->delete();
    }

    /** @return list<string> */
    public function tokens(User $user): array
    {
        return Device::where('user_id', $user->id)->orderByDesc('updated_at')->pluck('token')->all();
    }

    public function count(User $user): int
    {
        return Device::where('user_id', $user->id)->count();
    }
}
