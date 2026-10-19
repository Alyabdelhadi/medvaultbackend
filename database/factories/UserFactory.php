<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $local = '70'.fake()->unique()->numerify('######');

        return [
            'username' => Str::lower($first.$last).fake()->unique()->numberBetween(1, 99999),
            'email' => null,
            'password' => static::$password ??= Hash::make('password'),
            'display_name' => "$first $last",
            'first_name' => $first,
            'last_name' => $last,
            'mobile_number' => $local,
            'mobile_e164' => '961'.$local,
            'mobile_cc' => '961',
            'mobile_local' => $local,
            'function_type' => 'P',
            'role' => User::ROLE_SUBSCRIBER,
            'remember_token' => Str::random(10),
        ];
    }
}
