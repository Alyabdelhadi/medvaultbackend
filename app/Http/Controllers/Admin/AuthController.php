<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\Passwords;
use App\Services\Audit\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin sign-in by username or email, like wp-login.php. Imported WordPress
 * passwords work and are upgraded on the way in. Only accounts that can open
 * at least one admin page get a session (administrators, and accounts whose
 * group grants a page); everyone else is told the same thing as for a wrong
 * password.
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('admin.home') : view('admin.login');
    }

    public function login(Request $request, Passwords $passwords, AuditTrail $audit): RedirectResponse
    {
        $request->validate(['login' => 'required|string', 'password' => 'required|string']);

        $login = mb_strtolower(trim($request->input('login')));
        $user = User::whereRaw('LOWER(username) = ?', [$login])->first()
            ?? User::whereRaw('LOWER(email) = ?', [$login])->first();

        $allowed = $user !== null
            && $passwords->check($user, (string) $request->input('password'))
            && $user->hasAdminAccess();

        if (! $allowed) {
            // Under the name typed, so guessing at one account shows up as such.
            $audit->record('auth.login_failed', 'Failed admin sign-in as "'.mb_substr($login, 0, 60).'"', $user, [], false, actorName: mb_substr($login, 0, 60));

            return back()->withInput($request->only('login'))->withErrors(['login' => 'Incorrect username or password.']);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $audit->record('auth.login', 'Signed in to the admin', $user, [], $user);

        return redirect()->intended(route('admin.home'));
    }

    public function logout(Request $request, AuditTrail $audit): RedirectResponse
    {
        $audit->record('auth.logout', 'Signed out of the admin', $request->user(), []);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /** The first page this account may open: the dashboard, for most. */
    public function home(Request $request): RedirectResponse
    {
        $route = $request->user()->firstAdminRoute();
        abort_if($route === null, 403, 'This account has no access to the admin.');

        return redirect()->route($route[0], array_slice($route, 1));
    }
}
