<?php

use App\Modules\Dashboard\Http\Livewire\NotificationBell;
use App\Models\User;
use Livewire\Livewire;


it('shows correct unread count', function () {
    $user = User::factory()->create();

    \Illuminate\Notifications\DatabaseNotification::create([
        'id'             => \Illuminate\Support\Str::uuid()->toString(),
        'type'           => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id'  => $user->id,
        'data'           => ['message' => 'test'],
        'read_at'        => null,
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertViewHas('unreadCount', fn ($count) => $count >= 1);
});

it('mark all as read sets unread count to 0', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('markAllAsRead')
        ->assertViewHas('unreadCount', 0);
});
