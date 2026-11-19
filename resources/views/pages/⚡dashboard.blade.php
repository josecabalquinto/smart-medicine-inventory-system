<?php

use App\Models\ApplicationSetting;
use App\Models\InventoryCount;
use App\Models\Medicine;
use App\Models\Scale;
use App\Services\DeviceReadingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function summary(): array
    {
        $base = Medicine::where('simulation', false)->where('active', true);

        return ['active' => (clone $base)->count(), 'low' => (clone $base)->whereNotNull('quantity')->whereColumn('quantity', '<=', 'low_stock_threshold')->count(), 'uncounted' => (clone $base)->whereNull('quantity')->count()];
    }

    #[Computed]
    public function recent()
    {
        return InventoryCount::with(['medicine', 'user'])
            ->whereHas('medicine', fn($q) => $q->where('simulation', false))
            ->latest('id')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function lowStock()
    {
        return Medicine::where('simulation', false)->where('active', true)->whereNotNull('quantity')->whereColumn('quantity', '<=', 'low_stock_threshold')->orderBy('quantity')->limit(5)->get();
    }

    #[Computed]
    public function scaleStatus()
    {
        return app(DeviceReadingService::class)->status(Scale::where('simulation', false)->first());
    }

    #[Computed]
    public function pharmacyName()
    {
        return ApplicationSetting::findOrFail(1)->name;
    }
}; ?>
<div wire:poll.10s>
    <x-page-heading :title="$this->pharmacyName">
        <flux:button variant="primary" icon="scale" :href="route('count-stock')" wire:navigate>Count Stock</flux:button>
    </x-page-heading>
    <div class="mb-8 grid gap-5 sm:grid-cols-3">
        @foreach (['active' => 'Active medicines', 'low' => 'Low-stock medicines', 'uncounted' => 'Not counted yet'] as $key => $label)
            <x-inventory-card wire:key="stat-{{ $key }}">
                <p class="text-sm text-zinc-500">{{ $label }}</p>
                <p class="mt-4 text-4xl font-semibold tracking-tight">{{ $this->summary[$key] }}</p>
            </x-inventory-card>
        @endforeach
    </div>
    <div class="grid gap-6 xl:grid-cols-[1.65fr_1fr]">
        <x-inventory-card>
            <div class="mb-6 flex items-center justify-between gap-3">
                <flux:heading size="lg">Recent confirmed counts</flux:heading>
                <flux:button size="sm" variant="ghost" :href="route('count-history')" wire:navigate>View history
                </flux:button>
            </div>
            <div class="divide-y divide-zinc-100">
                @forelse($this->recent as $count)
                    <div class="flex items-center justify-between gap-4 py-5" wire:key="recent-{{ $count->id }}">
                        <div>
                            <p class="font-medium">{{ $count->medicine->generic_name }} {{ $count->medicine->strength }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $count->user->name }} &middot;
                                {{ $count->created_at->format('d M Y, H:i') }} &middot;
                                {{ str_replace('_', ' ', $count->source) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold">{{ number_format($count->new_quantity) }} pieces</p>
                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $count->difference === null ? 'First count' : sprintf('%+d', $count->difference) . ' adjustment' }}
                            </p>
                        </div>
                </div>@empty<div class="py-12">
                        <flux:icon icon="clipboard-document-check" class="mb-4 size-9 text-[#176B65]" />
                        <flux:heading>Your first count starts here</flux:heading>
                        <flux:text class="mt-2">Register a medicine, prepare the scale, and confirm the complete stock
                            balance. Live readings never change inventory on their own.</flux:text>
                    </div>
                @endforelse
            </div>
        </x-inventory-card>
        <div class="space-y-6">
            <x-inventory-card>
                <flux:heading size="lg">Scale connection</flux:heading>
                <div class="my-5">
                    <flux:badge>{{ $this->scaleStatus }}</flux:badge>
                </div>
                <flux:text>The scale continues local calibration and counting when Wi-Fi is unavailable.</flux:text>
            </x-inventory-card>
            <x-inventory-card>
                <flux:heading size="lg">Needs attention</flux:heading>
                <div class="mt-4 space-y-4">
                    @forelse($this->lowStock as $m)
                        <div wire:key="low-{{ $m->id }}" class="flex justify-between gap-3 text-sm">
                            <span>{{ $m->generic_name }} {{ $m->strength }}</span>
                            <flux:badge color="amber">{{ $m->quantity }} left</flux:badge>
                    </div>@empty<flux:text>No confirmed low-stock balances.</flux:text>
                    @endforelse
                </div>
            </x-inventory-card>
        </div>
    </div>
</div>
