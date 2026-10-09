<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $r)
    {
        $data = $r->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1024']);
        $key = 'login:'.hash('sha256', Str::lower($data['email']).'|'.$r->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan. Coba kembali setelah satu menit.']);
        }
        if (! Auth::attempt([...$data, 'active' => true], false)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
