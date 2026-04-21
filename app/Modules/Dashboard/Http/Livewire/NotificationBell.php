<?php

namespace App\Modules\Dashboard\Http\Livewire;

use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Polling;
use Livewire\Component;

class NotificationBell extends Component
{
    public int $unreadCount = 0;
    public bool $open = false;

    #[Polling('3s')]
    public function poll(): void
    {
        $this->unreadCount = auth()->user()?->unreadNotifications()->count() ?? 0;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function markAsRead(string $id): void
    {
        DatabaseNotification::find($id)?->markAsRead();
        $this->unreadCount = auth()->user()?->unreadNotifications()->count() ?? 0;
    }

    public function markAllAsRead(): void
    {
        auth()->user()?->unreadNotifications()->update(['read_at' => now()]);
        $this->unreadCount = 0;
    }

    public function render()
    {
        $this->unreadCount = auth()->user()?->unreadNotifications()->count() ?? 0;

        $notifications = $this->open
            ? (auth()->user()?->notifications()->limit(5)->get() ?? collect())
            : collect();

        return view('livewire.dashboard.notification-bell', [
            'notifications' => $notifications,
        ]);
    }
}
