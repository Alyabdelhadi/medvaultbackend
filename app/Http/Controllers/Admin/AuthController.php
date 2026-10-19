<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\Passwords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admin sign-in by username or email, like wp-login.php. Imported WordPress
 * passwords work and are upgraded on the way in. Only administrators and
 * assigned lab managers get a session; everyone else is told the same thing
 * as for a wrong password.
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('admin.home') : view('admin.login');
    }

    public function login(Request $request, Passwords $passwords): RedirectResponse
    {
        $request->validate(['login' => 'required|string', 'password' => 'required|string']);

        $login = mb_strtolower(trim($request->input('login')));
        $user = User::whereRaw('LOWER(username) = ?', [$login])->first()
            ?? User::whereRaw('LOWER(email) = ?', [$login])->first();

        $allowed = $user !== null
            && $passwords->check($user, (string) $request->input('password'))
            && Gate::forUser($user)->any(['admin', 'manage-users']);

        if (! $allowed) {
            return back()->withInput($request->only('login'))->withErrors(['login' => 'Incorrect username or password.']);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /** Administrators land on the dashboard; a lab manager on their own patients. */
    public function home(): RedirectResponse
    {
        return Gate::allows('admin')
            ? redirect()->route('admin.dashboard')
            : redirect()->route('admin.users.index', 'patients');
    }
}
