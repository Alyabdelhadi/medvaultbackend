<?php

namespace App\Services\Accounts;

use App\Models\User;
use App\Services\Labs\LabConfig;

/** build_profile_data(): the account as the app stores it after sign-in. */
final class Profile
{
    public static function of(User $user): array
    {
        return [
            'user_id' => (int) $user->id,
            'username' => (string) $user->username,
            // WordPress fell back to the core field, which is '' when unset.
            'first_name' => (string) ($user->first_name ?? ''),
            'last_name' => (string) ($user->last_name ?? ''),
            'dob' => $user->dob ?: null,
            'gender' => $user->gender ?: null,
            // user_email is '' when there is none, never null.
            'email' => (string) ($user->email ?? ''),
            'mobile_number' => $user->mobile_number ?: null,
            'profile_pic' => $user->profile_pic ?: null,
            'id_image' => $user->id_image ?: null,
            'insurance_image' => $user->insurance_image ?: null,
            'blood_type' => $user->blood_type ?: null,
            'function_type' => $user->functionType(),
            // Profiles the app can switch between, primary first.
            'roles' => $user->roles(),
            // Feature flags the app reads at sign-in and on profile refresh.
            'ai_summary_enabled' => LabConfig::aiSummaryEnabled(),
        ];
    }
}
