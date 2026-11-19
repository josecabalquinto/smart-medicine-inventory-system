<?php

use App\Models\ApplicationSetting;
use App\Models\Scale;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings')] class extends Component {
    public string $name = '';

    public int $staleSeconds = 10;

    public int $offlineSeconds = 30;

    public string $deviceIdentifier = 'esp32-01';

    public string $deviceName = 'Dispensary scale';

    public bool $deviceActive = true;

    #[Locked]
    public ?string $plaintext = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $s = ApplicationSetting::findOrFail(1);
        $this->name = $s->name;
        $this->staleSeconds = $s->stale_seconds;
        $this->offlineSeconds = $s->offline_seconds;
        $device = Scale::where('simulation', false)->first();
        if ($device) {
            $this->deviceIdentifier = $device->device_identifier;
            $this->deviceName = $device->name;
            $this->deviceActive = $device->active;
        }
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate(['name' => 'required|string|max:100', 'staleSeconds' => 'required|integer|min:3|max:120', 'offlineSeconds' => 'required|integer|gt:staleSeconds|max:600', 'deviceIdentifier' => 'required|alpha_dash|max:100', 'deviceName' => 'required|string|max:100', 'deviceActive' => 'boolean']);
        $scale = Scale::firstOrCreate(['simulation' => false], ['device_identifier' => $this->deviceIdentifier, 'name' => $this->deviceName]);
        Gate::authorize('update', $scale);
        DB::transaction(function () use ($scale) {
            ApplicationSetting::findOrFail(1)->update(['name' => $this->name, 'stale_seconds' => $this->staleSeconds, 'offline_seconds' => $this->offlineSeconds]);
            $scale->update(['device_identifier' => $this->deviceIdentifier, 'name' => $this->deviceName, 'active' => $this->deviceActive]);
        });
        Flux::toast('Settings saved.', variant: 'success');
    }

    #[Computed]
    public function device()
    {
        return Scale::where('simulation', false)->first();
    }

    public function dismissCredential(): void
    {
        $this->plaintext = null;
    }

    public function rotate(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $scale = Scale::where('simulation', false)->firstOrFail();
        Gate::authorize('update', $scale);
        $this->plaintext = Str::random(64);
        $scale->update(['credential_hash' => Hash::make($this->plaintext)]);
        Flux::toast('Credential generated. Copy it now; it will not be shown again.');
    }
}; ?>
<div>
    <x-page-heading title="Settings" />
    <div class="grid gap-6 xl:grid-cols-2">
        <x-inventory-card>
            <form wire:submit="save" class="space-y-5">
                <flux:heading size="lg">Application &amp; scale</flux:heading>
                <x-form-errors />
                <flux:input wire:model="name" label="Pharmacy / application display name" />
                <flux:input wire:model="deviceIdentifier" label="Device identifier" />
                <flux:input wire:model="deviceName" label="Scale display name" />
                <flux:checkbox wire:model="deviceActive" label="Scale active" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input type="number" wire:model="staleSeconds" label="Stale after (seconds)" />
                    <flux:input type="number" wire:model="offlineSeconds" label="Offline after (seconds)" />
                </div>
                <flux:button type="submit" variant="primary">Save settings</flux:button>
            </form>
        </x-inventory-card>
        <div class="space-y-6">
            <x-inventory-card>
                <flux:heading size="lg">Device credential</flux:heading>
                @if ($this->device)
                    <div class="mt-4 space-y-2 text-sm text-zinc-500">
                        <p>Last seen:
                            {{ $this->device->last_seen_at ? $this->device->last_seen_at->format('d M Y H:i:s') . ' UTC' : 'Never' }}
                        </p>
                        <p>Firmware: {{ $this->device->firmware_version ?? 'Not reported' }}</p>
                    </div>
                @endif
                <flux:text class="mt-3 mb-5">Create a unique login key for this physical scale. Generating a new key
                    immediately disables the old one.</flux:text>
                <flux:button wire:click="rotate"
                    wire:confirm="Generate a new credential? The device must be updated before it can reconnect.">
                    Provision / rotate credential</flux:button>
                @if ($plaintext)
                    <div class="mt-5 rounded-xl bg-amber-50 p-4">
                        <p class="mb-2 text-sm font-medium">Copy this credential now</p>
                        <code class="break-all text-sm">{{ $plaintext }}</code>
                        <flux:button class="mt-3" size="sm" wire:click="dismissCredential">Dismiss</flux:button>
                    </div>
                @endif
            </x-inventory-card>
            <x-inventory-card>
                <flux:heading size="lg">Playground</flux:heading>
                <flux:text class="mt-3">Test freely without affecting real data</flux:text>
                @if (app()->environment('local', 'testing') && config('inventory.simulation'))
                    <flux:button class="mt-5" :href="route('simulator')" wire:navigate>Open isolated simulator
                    </flux:button>
                @endif
            </x-inventory-card>
        </div>
    </div>
</div>
