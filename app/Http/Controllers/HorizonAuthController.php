<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HorizonSessionAuthMiddleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HorizonAuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        if ((bool) $request->session()->get(HorizonSessionAuthMiddleware::SESSION_KEY, false)) {
            return redirect()->intended($this->horizonUrl());
        }

        return view('horizon.login', [
            'horizonPath' => trim((string) config('horizon.path', 'horizon'), '/'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $expectedUser = config('horizon.auth.user');
        $expectedPassword = config('horizon.auth.password');

        if (! $expectedUser || ! $expectedPassword) {
            abort(403, 'Horizon authentication is not configured.');
        }

        if (
            hash_equals((string) $expectedUser, $credentials['username'])
            && hash_equals((string) $expectedPassword, $credentials['password'])
        ) {
            $request->session()->regenerate();
            $request->session()->put(HorizonSessionAuthMiddleware::SESSION_KEY, true);
            $request->session()->put('horizon_user', $credentials['username']);

            return redirect()->intended($this->horizonUrl());
        }

        return back()
            ->withErrors(['username' => 'Username atau password Horizon tidak sesuai.'])
            ->onlyInput('username');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget([
            HorizonSessionAuthMiddleware::SESSION_KEY,
            'horizon_user',
        ]);
        $request->session()->regenerateToken();

        return redirect()->route('horizon.login');
    }

    private function horizonUrl(): string
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');

        return url($horizonPath !== '' ? $horizonPath : '/');
    }
}
