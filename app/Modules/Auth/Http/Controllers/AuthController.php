<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $user = $this->authService->login($validated['email'], $validated['password']);
            $request->session()->regenerate();

            if ($user->isSuperAdmin()) {
                return redirect()->route('superadmin.tenants.index');
            }

            return redirect()->intended('/dashboard');
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            return back()->withErrors(['email' => 'Email atau password salah.'])->withInput();
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->authService->logout();

        return redirect()->route('auth.login');
    }
}
