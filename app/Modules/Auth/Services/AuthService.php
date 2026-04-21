<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    public function login(string $email, string $password): User
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            throw new AuthenticationException('Invalid credentials.');
        }

        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function logout(): void
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
