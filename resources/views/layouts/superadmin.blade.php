<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Super Admin') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900 antialiased">

<div class="flex h-screen overflow-hidden">
    <aside class="w-56 flex-shrink-0 bg-gray-900 text-gray-300 flex flex-col">
        <div class="h-16 flex items-center px-5 border-b border-gray-800">
            <span class="text-sm font-bold text-white">Super Admin</span>
        </div>
        <nav class="flex-1 py-4 px-3 space-y-1">
            <a href="{{ route('superadmin.tenants.index') }}"
               class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('superadmin.tenants.*') ? 'bg-gray-700 text-white' : 'hover:bg-gray-800' }}">
                Tenants
            </a>
            <a href="{{ route('superadmin.services.index') }}"
               class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('superadmin.services.*') ? 'bg-gray-700 text-white' : 'hover:bg-gray-800' }}">
                Services
            </a>
            <a href="{{ route('superadmin.billing.index') }}"
               class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('superadmin.billing.*') ? 'bg-gray-700 text-white' : 'hover:bg-gray-800' }}">
                Billing
            </a>
            <a href="{{ route('superadmin.usage.index') }}"
               class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('superadmin.usage.*') ? 'bg-gray-700 text-white' : 'hover:bg-gray-800' }}">
                LLM Usage
            </a>
        </nav>
        <div class="p-4 border-t border-gray-800 space-y-3">
            <div>
                <div class="text-sm font-medium text-white">{{ auth()->user()?->name }}</div>
                <div class="text-xs text-gray-500">Platform Super Admin</div>
            </div>
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit" class="w-full rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 hover:text-white">
                    Logout
                </button>
            </form>
        </div>
    </aside>

    <div class="flex-1 flex flex-col overflow-hidden">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6 flex-shrink-0">
            <span class="text-sm font-semibold">@yield('title')</span>
            <span class="text-sm text-gray-500">{{ auth()->user()?->email }}</span>
        </header>

        @if(session('success'))
            <div class="mx-6 mt-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif

        <main class="flex-1 overflow-y-auto p-6">
            @yield('content')
        </main>
    </div>
</div>

</body>
</html>
