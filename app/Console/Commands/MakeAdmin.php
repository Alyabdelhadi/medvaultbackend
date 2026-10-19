<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Accounts\Passwords;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates an administrator, or promotes an existing account. Imported
 * WordPress administrators keep their role and password, so this is only
 * needed on a fresh install or to recover access.
 */
#[Signature('medvault:make-admin {username} {--email=} {--password= : Prompted for when omitted}')]
#[Description('Create an admin login, or make an existing account an administrator')]
class MakeAdmin extends Command
{
    public function handle(Passwords $passwords): int
    {
        $username = (string) $this->argument('username');
        $user = User::whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->first();

        $password = (string) ($this->option('password') ?: ($user === null ? $this->secret('Password') : ''));
        if ($user === null && strlen($password) < 8) {
            $this->components->error('Use a password of at least 8 characters.');

            return self::FAILURE;
        }

        $user ??= new User;
        $user->forceFill([
            'username' => $user->username ?? $username,
            'email' => $this->option('email') ?: $user->email,
            'display_name' => $user->display_name ?: $username,
            'password' => $user->password ?? '',
            'role' => User::ROLE_ADMIN,
        ])->save();

        if ($password !== '') {
            $passwords->set($user, $password);
        }

        $this->components->info("{$user->username} is an administrator. Sign in at ".url('admin/login'));

        return self::SUCCESS;
    }
}
