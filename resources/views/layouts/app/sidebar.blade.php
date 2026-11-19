<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>@include('partials.head')</head>

<body class="min-h-screen bg-white text-zinc-800">
    <flux:sidebar sticky collapsible="mobile" class="border-0! rounded-r-3xl! bg-[#EAF1EF]! px-5! py-8! lg:w-64!">
        <flux:sidebar.header><a href="{{ route('dashboard') }}" wire:navigate
                class="flex items-center gap-3 text-xl font-semibold tracking-tight"><span
                    class="flex size-10 items-center justify-center rounded-xl text-white">
                    <span class="flex h-9 w-9 items-center justify-center rounded-md">
                        <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                    </span>
                </span> SmartMed<span class="sr-only">Inventory</span></a>
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>
        <div class="px-3 text-sm text-zinc-500">Medicine Inventory System</div>
        <flux:separator class="mb-4" />
        <flux:sidebar.nav>
            @foreach (['dashboard' => ['home', 'Dashboard'], 'medicines' => ['squares-2x2', 'Medicines'], 'count-stock' => ['scale', 'Count Stock'], 'calibration-profiles' => ['adjustments-horizontal', 'Calibration Profiles'], 'count-history' => ['clock', 'Count History']] as $route => [$icon, $label])
                <flux:sidebar.item :icon="$icon" :href="route($route)" :current="request()->routeIs($route)"
                    wire:navigate wire:key="nav-{{ $route }}" class="py-5 rounded-2xl">{{ $label }}
                </flux:sidebar.item>
            @endforeach
            @if (auth()->user()->isAdmin())
                <flux:separator class="my-5" />
                {{-- <flux:sidebar.item icon="users" :href="route('users')" :current="request()->routeIs('users')"
                    wire:navigate>Users</flux:sidebar.item> --}}
                <flux:sidebar.item class="py-5" icon="cog-6-tooth" :href="route('inventory-settings')"
                    :current="request()->routeIs('inventory-settings')" wire:navigate>Device Settings
                </flux:sidebar.item>
            @endif
        </flux:sidebar.nav>
        <flux:spacer />
        {{-- <div
            class="sidebar-note my-6 rounded-xl border border-white/70 bg-white/45 p-4 text-xs leading-relaxed text-zinc-600">
            Estimates support stock checks. Always confirm medicine identity and review the count.</div> --}}
        <x-desktop-user-menu class="py-5" :name="auth()->user()->name" />
    </flux:sidebar>
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle icon="bars-2" />
        <flux:spacer /><span class="text-sm">SmartMed</span>
    </flux:header>
    {{ $slot }}
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist
    @fluxScripts
</body>

</html>
