<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Actions\ActivateInvitationAction;
use App\Modules\Tenancy\DTOs\ActivateInvitationDTO;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class ActivateInvitationController extends Controller
{
    public function __construct(private readonly ActivateInvitationAction $action) {}

    public function show(Request $request): View
    {
        return view('auth.activate', ['token' => $request->query('token')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = $this->action->execute(ActivateInvitationDTO::fromArray($validated));
            auth()->login($user);

            return redirect('/dashboard')->with('success', 'Akun berhasil diaktifkan!');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }
    }
}
